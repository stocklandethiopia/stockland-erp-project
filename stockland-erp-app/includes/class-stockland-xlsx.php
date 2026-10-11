<?php
/**
 * Bounded streaming XLSX reader for Stockland ERP imports.
 *
 * @package Stockland_ERP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded XLSX reader for constrained WordPress/shared hosting.
 *
 * Workbook metadata remains small SimpleXML reads. Operational import sheets are
 * streamed row-by-row through ZipArchive::getStream() + XMLReader so a full
 * worksheet XML document is never materialized in PHP memory.
 */
class Stockland_XLSX {
	const MAX_FILE_BYTES             = 8 * 1024 * 1024;
	const MAX_SHARED_STRINGS         = 250000;
	const MAX_SHARED_XML_BYTES       = 32 * 1024 * 1024;
	const MAX_SHEET_XML_BYTES        = 64 * 1024 * 1024;
	const MAX_PHYSICAL_ROW_NODES     = 15050;
	const MAX_CELLS_PER_ROW          = 250;
	const MAX_ROW_XML_BYTES          = 2 * 1024 * 1024;
	const MAX_ZIP_ENTRIES            = 300;
	const MAX_ZIP_UNCOMPRESSED_BYTES = 96 * 1024 * 1024;
	const MAX_ZIP_RATIO              = 200;

	/**
	 * Open workbook archive.
	 *
	 * @var ZipArchive|null
	 */
	private $zip = null;
	/**
	 * Reserved compatibility field for legacy reader implementations.
	 *
	 * @var mixed
	 */
	private $pcl = null;
	/**
	 * Shared strings loaded from the workbook.
	 *
	 * @var array<int, string>
	 */
	private $shared = array();
	/**
	 * Worksheet names mapped to archive paths.
	 *
	 * @var array<string, string>
	 */
	private $sheets = array();

	/**
	 * Opens and validates an XLSX archive within configured resource limits.
	 *
	 * @param string $file Temporary upload path.
	 * @return void
	 * @throws Exception When the file is invalid, oversized or cannot be opened.
	 */
	public function __construct( $file ) {
		if ( ! is_file( $file ) || filesize( $file ) <= 0 ) {
			throw new Exception( 'Invalid XLSX upload.' );
		}
		$size = filesize( $file );
		if ( $size > self::MAX_FILE_BYTES ) {
			throw new Exception( 'XLSX file is too large. Maximum allowed size is 8 MB.' );
		}
		$fh        = fopen( $file, 'rb' );
		$signature = $fh ? fread( $fh, 4 ) : false;
		if ( $fh ) {
			fclose( $fh );
		}
		if ( "PK\x03\x04" !== $signature && "PK\x05\x06" !== $signature && "PK\x07\x08" !== $signature ) {
			throw new Exception( 'Invalid XLSX container signature. Upload a real ZIP-based XLSX workbook.' );
		}
		if ( class_exists( 'ZipArchive' ) ) {
			$this->zip = new ZipArchive();
			if ( $this->zip->open( $file ) !== true ) {
				throw new Exception( 'Could not open XLSX file.' );
			}
			$entries = (int) $this->zip->numFiles;
			if ( $entries <= 0 || $entries > self::MAX_ZIP_ENTRIES ) {
				throw new Exception( 'XLSX contains an unsafe number of ZIP entries.' );
			}
			$uncompressed = 0;
			for ( $i = 0;$i < $entries;$i++ ) {
				$st = $this->zip->statIndex( $i );
				if ( ! is_array( $st ) ) {
					throw new Exception( 'Could not inspect an XLSX ZIP entry.' );
				}
				$usize         = (int) ( $st['size'] ?? 0 );
				$csize         = (int) ( $st['comp_size'] ?? 0 );
				$uncompressed += $usize;
				if ( $uncompressed > self::MAX_ZIP_UNCOMPRESSED_BYTES ) {
					throw new Exception( 'XLSX expands beyond the shared-host safety limit.' );
				}
				if ( $csize > 1024 && $usize > ( $csize * self::MAX_ZIP_RATIO ) ) {
					throw new Exception( 'XLSX contains an unsafe ZIP decompression ratio.' );
				}
			}
		} else {
			$pcl = ABSPATH . 'wp-admin/includes/class-pclzip.php';
			if ( ! class_exists( 'PclZip' ) && file_exists( $pcl ) ) {
				require_once $pcl;
			}
			if ( ! class_exists( 'PclZip' ) ) {
				throw new Exception( 'This hosting does not provide an XLSX ZIP reader.' );
			}
			$this->pcl = new PclZip( $file );
		}
		$this->load_shared();
		$this->load_sheets();
	}

	/**
	 * Closes the workbook archive and releases reader resources.
	 *
	 * @return void
	 */
	public function __destruct() {
		if ( $this->zip instanceof ZipArchive ) {
			$this->zip->close();
		}
	}

	/**
	 * Resolves a workbook relationship target to its archive entry.
	 *
	 * @param string $name Relationship target name.
	 * @return string|null Normalized archive path, or null when the entry cannot be read.
	 */
	private function get_from_name( $name ) {
		if ( $this->zip ) {
			$v = $this->zip->getFromName( $name );
			return false === $v ? null : $v;
		}
		$r = $this->pcl->extract( PCLZIP_OPT_BY_NAME, $name, PCLZIP_OPT_EXTRACT_AS_STRING );
		return ( is_array( $r ) && isset( $r[0]['content'] ) ) ? $r[0]['content'] : null;
	}

	/**
	 * Returns the uncompressed size of a workbook archive entry.
	 *
	 * @param string $name Archive entry name.
	 * @return int|null Entry size, or null when the archive cannot report it.
	 */
	private function entry_size( $name ) {
		if ( ! $this->zip ) {
			return null;
		}
		$stat = $this->zip->statName( $name );
		if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
			return null;
		}
		return (int) $stat['size'];
	}

	/**
	 * Loads a bounded XML metadata entry from the workbook.
	 *
	 * @param string $name Archive entry name.
	 * @return SimpleXMLElement|null Parsed XML, or null when the archive entry is unavailable.
	 */
	private function xml( $name ) {
		$raw = $this->get_from_name( $name );
		if ( null === $raw ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$x = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
			if ( false === $x ) {
				$detail = 'Invalid XML in workbook.';
				$errs   = libxml_get_errors();
				if ( ! empty( $errs ) ) {
					$detail .= ' ' . trim( $errs[0]->message );
				}
				throw new Exception( $detail );
			}
			return $x;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous ); }
	}

	/**
	 * Concatenates text nodes from a shared-string or inline-string element.
	 *
	 * @param mixed $node XML node containing text runs.
	 * @return string Decoded cell text.
	 */
	private function text_parts( $node ) {
		$parts   = array();
		$matches = $node->xpath( './/*[local-name()="t"]' );
		foreach ( (array) $matches as $t ) {
			$parts[] = (string) $t;
		}
		return implode( '', $parts );
	}

	/**
	 * Loads and validates the workbook shared-string table within size limits.
	 *
	 * @return void
	 * @throws Exception When shared-string XML is invalid or exceeds limits.
	 */
	private function load_shared() {
		if ( ! $this->zip && ! $this->pcl ) {
			return;
		}
		$size = $this->entry_size( 'xl/sharedStrings.xml' );
		if ( null !== $size && $size > self::MAX_SHARED_XML_BYTES ) {
			throw new Exception( 'XLSX shared strings section is too large for the hosting safety limit.' );
		}
		if ( $this->zip && class_exists( 'XMLReader' ) && method_exists( 'XMLReader', 'fromStream' ) ) {
			$stream = $this->zip->getStream( 'xl/sharedStrings.xml' );
			if ( false !== $stream ) {
				$reader = XMLReader::fromStream( $stream, null, LIBXML_NONET | LIBXML_COMPACT );
				$count  = 0;
				while ( $reader->read() ) {
					if ( XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
						if ( ++$count > self::MAX_SHARED_STRINGS ) {
							throw new Exception( 'XLSX contains too many shared strings.' );
						}
						$raw = $reader->readOuterXml();
						if ( strlen( $raw ) > self::MAX_ROW_XML_BYTES ) {
							throw new Exception( 'XLSX shared string entry is too large.' );
						}
						$previous = libxml_use_internal_errors( true );
						try {
							$si = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
							if ( false === $si ) {
								throw new Exception( 'Invalid shared string XML in workbook.' );
							}
							$this->shared[] = $this->text_parts( $si );
						} finally {
							libxml_clear_errors();
							libxml_use_internal_errors( $previous ); }
					}
				}
				$reader->close();
				fclose( $stream );
				return;
			}
		}
		$x = $this->xml( 'xl/sharedStrings.xml' );
		if ( ! $x ) {
			return;
		}
		$count = 0;
		foreach ( $x->si as $si ) {
			if ( ++$count > self::MAX_SHARED_STRINGS ) {
				throw new Exception( 'XLSX contains too many shared strings.' );
			}
			$this->shared[] = $this->text_parts( $si );
		}
	}

	/**
	 * Loads worksheet names and relationship targets from workbook metadata.
	 *
	 * @return void
	 * @throws Exception When workbook or relationship metadata is invalid.
	 */
	private function load_sheets() {
		$wb   = $this->xml( 'xl/workbook.xml' );
		$rels = $this->xml( 'xl/_rels/workbook.xml.rels' );
		if ( ! $wb || ! $rels ) {
			throw new Exception( 'Invalid XLSX structure: workbook relationships are missing.' );
		}

		// OOXML workbook and relationships parts normally use default XML namespaces.
		// SimpleXML direct property access may therefore return empty children on valid
		// Excel files. Use local-name() XPath to recognize standard namespace-qualified XML.
		$map                = array();
		$relationship_nodes = $rels->xpath( '//*[local-name()="Relationship"]' );
		foreach ( (array) $relationship_nodes as $r ) {
			$target = (string) $r['Target'];
			if ( '' === $target ) {
				continue;
			}
			if ( strpos( $target, '/' ) === 0 ) {
				$target = ltrim( $target, '/' );
			} else {
				$target = 'xl/' . $target;
			}
			$target                   = preg_replace( '#^xl/xl/#', 'xl/', $target );
			$map[ (string) $r['Id'] ] = $target;
		}

		$sheet_nodes = $wb->xpath( '//*[local-name()="sheets"]/*[local-name()="sheet"]' );
		if ( empty( $sheet_nodes ) ) {
			throw new Exception( 'Invalid XLSX structure: no worksheet is present.' );
		}
		foreach ( (array) $sheet_nodes as $s ) {
			$name  = (string) $s['name'];
			$attrs = $s->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
			$rid   = isset( $attrs['id'] ) ? (string) $attrs['id'] : '';
			if ( '' !== $name && '' !== $rid && isset( $map[ $rid ] ) ) {
				$this->sheets[ $name ] = $map[ $rid ];
			}
		}
		if ( empty( $this->sheets ) ) {
			throw new Exception( 'Invalid XLSX structure: worksheet relationship targets are missing.' );
		}
	}

	/**
	 * Converts an Excel cell reference into a zero-based column index.
	 *
	 * @param string $cell_ref Excel cell reference, such as A1.
	 * @return int Zero-based column index.
	 */
	private function col( $ref ) {
		if ( ! preg_match( '/([A-Z]+)\d+/i', $ref, $m ) ) {
			return 0;
		}
		$n = 0;
		foreach ( str_split( strtoupper( $m[1] ) ) as $c ) {
			$n = $n * 26 + ( ord( $c ) - 64 );
		}
		return $n - 1;
	}

	/**
	 * Decodes a cell value using its type and the shared-string table.
	 *
	 * @param mixed $c XML cell element.
	 * @return mixed Decoded cell value.
	 */
	private function value( $c ) {
		$v = (string) $c->v;
		$t = (string) $c['t'];
		if ( 's' === $t ) {
			return $this->shared[ (int) $v ] ?? '';
		}
		if ( 'inlineStr' === $t ) {
			return $this->text_parts( $c->is );
		}
		if ( 'b' === $t ) {
			return ( '1' === (string) $v ) ? '1' : '0';
		}
		return $v;
	}

	/**
	 * Returns worksheet names discovered in the workbook.
	 *
	 * @return array<string> Worksheet names.
	 */
	public function sheet_names() {
		return array_keys( $this->sheets ); }

	/**
	 * Stream worksheet rows as ['row_number'=>N,'values'=>[column_index=>value]].
	 * Data is decoded one Excel row at a time; callers decide when to stop.
	 *
	 * @param string $sheet Worksheet name.
	 * @param int    $max_physical_rows Maximum physical row nodes to inspect.
	 * @return Generator<int, array> Worksheet rows with physical row numbers.
	 * @throws Exception When the reader, worksheet or row exceeds validation limits.
	 */
	public function stream_rows( $sheet, $max_physical_rows = self::MAX_PHYSICAL_ROW_NODES ) {
		if ( ! $this->zip || ! class_exists( 'XMLReader' ) ) {
			throw new Exception( 'This hosting does not provide the required streaming XLSX reader (ZipArchive + XMLReader).' );
		}
		if ( ! isset( $this->sheets[ $sheet ] ) ) {
			throw new Exception( 'Worksheet not found: ' . esc_html( $sheet ) );
		}
		$path = $this->sheets[ $sheet ];
		$size = $this->entry_size( $path );
		if ( null !== $size && $size > self::MAX_SHEET_XML_BYTES ) {
			throw new Exception( 'Worksheet ' . esc_html( $sheet ) . ' exceeds the XLSX uncompressed safety limit. Split the workbook.' );
		}
		$stream = $this->zip->getStream( $path );
		if ( false === $stream ) {
			throw new Exception( 'Could not open worksheet stream: ' . esc_html( $sheet ) . '.' );
		}
		$reader   = null;
		$temp_xml = '';
		try {
			// XMLReader::fromStream() is PHP 8.4+. InfinityFree currently provides
			// PHP 8.4, but keep a safe disk-backed fallback for hosts with an older
			// XMLReader build. The fallback remains streaming and does not materialize
			// the worksheet XML in PHP memory.
			if ( method_exists( 'XMLReader', 'fromStream' ) ) {
				$reader = XMLReader::fromStream( $stream, null, LIBXML_NONET | LIBXML_COMPACT );
			} else {
				$temp_xml = tempnam( sys_get_temp_dir(), 'sl_xlsx_' );
				if ( ! $temp_xml ) {
					throw new Exception( 'Could not create a temporary XLSX worksheet file on this host.' );
				}
				$out = fopen( $temp_xml, 'wb' );
				if ( ! $out ) {
					throw new Exception( 'Could not open temporary XLSX worksheet file.' );
				}
				stream_copy_to_stream( $stream, $out );
				fclose( $out );
				$reader = XMLReader::open( $temp_xml, null, LIBXML_NONET | LIBXML_COMPACT );
				if ( false === $reader ) {
					throw new Exception( 'Could not initialize the XML worksheet reader.' );
				}
			}
			$nodes = 0;
			while ( $reader->read() ) {
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XMLReader's native API defines nodeType/localName in camelCase.
				if ( XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName ) {
				// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}
				if ( ++$nodes > $max_physical_rows ) {
					throw new Exception( 'Worksheet ' . esc_html( $sheet ) . ' exceeds the physical row safety limit. Split the workbook.' );
				}
				$row_number = (int) $reader->getAttribute( 'r' );
				$raw        = $reader->readOuterXml();
				if ( strlen( $raw ) > self::MAX_ROW_XML_BYTES ) {
					throw new Exception( 'Worksheet ' . esc_html( $sheet ) . ' contains an oversized row. Split or simplify the workbook.' );
				}
				$previous = libxml_use_internal_errors( true );
				try {
					$row = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
					if ( false === $row ) {
						throw new Exception( 'Invalid worksheet row XML in ' . esc_html( $sheet ) . '.' );
					}
					$values = array();
					$cells  = 0;
					foreach ( $row->c as $c ) {
						if ( ++$cells > self::MAX_CELLS_PER_ROW ) {
							throw new Exception( 'Worksheet ' . esc_html( $sheet ) . ' contains a row with too many cells.' );
						}
						$values[ $this->col( (string) $c['r'] ) ] = $this->value( $c );
					}
				} finally {
					libxml_clear_errors();
					libxml_use_internal_errors( $previous ); }
				yield array(
					'row_number' => $row_number,
					'values'     => $values,
				);
			}
			$reader->close();
		} finally {
			fclose( $stream );
			if ( $temp_xml && is_file( $temp_xml ) ) {
				wp_delete_file( $temp_xml );
			}
		}
	}

	/**
	 * Finds the first worksheet whose headers contain all requested terms.
	 *
	 * @param array $needles Required header terms.
	 * @param int   $max_header_row Last row to consider as a header.
	 * @return array Worksheet, header row, rows and normalized headers.
	 */
	public function rows_by_headers( $needles = array(), $max_header_row = 10 ) {
		foreach ( $this->sheet_names() as $sheet ) {
			for ( $h = 1;$h <= $max_header_row;$h++ ) {
				$rows = $this->rows( $sheet, $h );
				if ( empty( $rows ) ) {
					continue;
				}
				$headers = array_map( 'strtolower', array_keys( $rows[0] ) );
				$joined  = ' ' . implode( ' ', $headers ) . ' ';
				$ok      = true;
				foreach ( (array) $needles as $needle ) {
					if ( strpos( $joined, strtolower( (string) $needle ) ) === false ) {
						$ok = false;
						break;}
				}
				if ( $ok ) {
					return array(
						'sheet'      => $sheet,
						'header_row' => $h,
						'rows'       => $rows,
						'headers'    => $headers,
					);
				}
			}
		}
		return array(
			'sheet'      => null,
			'header_row' => null,
			'rows'       => array(),
			'headers'    => array(),
		);
	}

	/**
	 * Reads a worksheet into a header-keyed array for legacy callers.
	 *
	 * @param string $sheet Worksheet name.
	 * @param int    $header_row One-based header row.
	 * @return array Worksheet rows keyed by header names.
	 */
	public function rows( $sheet, $header_row = 3 ) {
		if ( ! isset( $this->sheets[ $sheet ] ) ) {
			return array();
		}
		$x = $this->xml( $this->sheets[ $sheet ] );
		if ( ! $x ) {
			return array();
		}
		$all = array();
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OOXML uses the schema-defined SimpleXML element name sheetData.
		foreach ( $x->sheetData->row as $row ) {
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$vals = array();
			foreach ( $row->c as $c ) {
				$vals[ $this->col( (string) $c['r'] ) ] = $this->value( $c );
			}
			$all[ (int) $row['r'] ] = $vals;
		}
		$headers = $all[ $header_row ] ?? array();
		$out     = array();
		foreach ( $all as $rn => $vals ) {
			if ( $rn <= $header_row ) {
				continue;
			}
			$r = array();
			foreach ( $headers as $i => $h ) {
				if ( '' !== $h ) {
					$r[ $h ] = $vals[ $i ] ?? null;
				}
			}
			if ( array_filter(
				$r,
				function ( $v ) {
					return null !== $v && '' !== $v;
				}
			) ) {
				$out[] = $r;
			}
		}
		return $out;
	}
}
