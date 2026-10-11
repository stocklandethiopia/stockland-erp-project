<?php
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

	private $zip    = null;
	private $pcl    = null;
	private $shared = array();
	private $sheets = array();

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
		if ( $signature !== "PK\x03\x04" && $signature !== "PK\x05\x06" && $signature !== "PK\x07\x08" ) {
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
		$this->loadShared();
		$this->loadSheets();
	}

	public function __destruct() {
		if ( $this->zip instanceof ZipArchive ) {
			@$this->zip->close();
		}
	}

	private function getFromName( $name ) {
		if ( $this->zip ) {
			$v = $this->zip->getFromName( $name );
			return $v === false ? null : $v;
		}
		$r = $this->pcl->extract( PCLZIP_OPT_BY_NAME, $name, PCLZIP_OPT_EXTRACT_AS_STRING );
		return ( is_array( $r ) && isset( $r[0]['content'] ) ) ? $r[0]['content'] : null;
	}

	private function entrySize( $name ) {
		if ( ! $this->zip ) {
			return null;
		}
		$stat = $this->zip->statName( $name );
		if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
			return null;
		}
		return (int) $stat['size'];
	}

	private function xml( $name ) {
		$raw = $this->getFromName( $name );
		if ( $raw === null ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$x = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
			if ( $x === false ) {
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

	private function textParts( $node ) {
		$parts   = array();
		$matches = $node->xpath( './/*[local-name()="t"]' );
		foreach ( (array) $matches as $t ) {
			$parts[] = (string) $t;
		}
		return implode( '', $parts );
	}

	private function loadShared() {
		if ( ! $this->zip && ! $this->pcl ) {
			return;
		}
		$size = $this->entrySize( 'xl/sharedStrings.xml' );
		if ( $size !== null && $size > self::MAX_SHARED_XML_BYTES ) {
			throw new Exception( 'XLSX shared strings section is too large for the hosting safety limit.' );
		}
		if ( $this->zip && class_exists( 'XMLReader' ) && method_exists( 'XMLReader', 'fromStream' ) ) {
			$stream = $this->zip->getStream( 'xl/sharedStrings.xml' );
			if ( $stream !== false ) {
				$reader = XMLReader::fromStream( $stream, null, LIBXML_NONET | LIBXML_COMPACT );
				$count  = 0;
				while ( $reader->read() ) {
					if ( $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si' ) {
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
							if ( $si === false ) {
								throw new Exception( 'Invalid shared string XML in workbook.' );
							}
							$this->shared[] = $this->textParts( $si );
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
			$this->shared[] = $this->textParts( $si );
		}
	}

	private function loadSheets() {
		$wb   = $this->xml( 'xl/workbook.xml' );
		$rels = $this->xml( 'xl/_rels/workbook.xml.rels' );
		if ( ! $wb || ! $rels ) {
			throw new Exception( 'Invalid XLSX structure: workbook relationships are missing.' );
		}

		// OOXML workbook and relationships parts normally use default XML namespaces.
		// SimpleXML direct property access may therefore return empty children on valid
		// Excel files. Use local-name() XPath to recognize standard namespace-qualified XML.
		$map               = array();
		$relationshipNodes = $rels->xpath( '//*[local-name()="Relationship"]' );
		foreach ( (array) $relationshipNodes as $r ) {
			$target = (string) $r['Target'];
			if ( $target === '' ) {
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

		$sheetNodes = $wb->xpath( '//*[local-name()="sheets"]/*[local-name()="sheet"]' );
		if ( empty( $sheetNodes ) ) {
			throw new Exception( 'Invalid XLSX structure: no worksheet is present.' );
		}
		foreach ( (array) $sheetNodes as $s ) {
			$name  = (string) $s['name'];
			$attrs = $s->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
			$rid   = isset( $attrs['id'] ) ? (string) $attrs['id'] : '';
			if ( $name !== '' && $rid !== '' && isset( $map[ $rid ] ) ) {
				$this->sheets[ $name ] = $map[ $rid ];
			}
		}
		if ( empty( $this->sheets ) ) {
			throw new Exception( 'Invalid XLSX structure: worksheet relationship targets are missing.' );
		}
	}

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

	private function value( $c ) {
		$v = (string) $c->v;
		$t = (string) $c['t'];
		if ( $t === 's' ) {
			return $this->shared[ (int) $v ] ?? '';
		}
		if ( $t === 'inlineStr' ) {
			return $this->textParts( $c->is );
		}
		if ( $t === 'b' ) {
			return ( (string) $v === '1' ) ? '1' : '0';
		}
		return $v;
	}

	public function sheet_names() {
		return array_keys( $this->sheets ); }

	/**
	 * Stream worksheet rows as ['row_number'=>N,'values'=>[column_index=>value]].
	 * Data is decoded one Excel row at a time; callers decide when to stop.
	 */
	public function stream_rows( $sheet, $max_physical_rows = self::MAX_PHYSICAL_ROW_NODES ) {
		if ( ! $this->zip || ! class_exists( 'XMLReader' ) ) {
			throw new Exception( 'This hosting does not provide the required streaming XLSX reader (ZipArchive + XMLReader).' );
		}
		if ( ! isset( $this->sheets[ $sheet ] ) ) {
			throw new Exception( 'Worksheet not found: ' . $sheet );
		}
		$path = $this->sheets[ $sheet ];
		$size = $this->entrySize( $path );
		if ( $size !== null && $size > self::MAX_SHEET_XML_BYTES ) {
			throw new Exception( 'Worksheet ' . $sheet . ' exceeds the XLSX uncompressed safety limit. Split the workbook.' );
		}
		$stream = $this->zip->getStream( $path );
		if ( $stream === false ) {
			throw new Exception( 'Could not open worksheet stream: ' . $sheet . '.' );
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
				if ( $reader === false ) {
					throw new Exception( 'Could not initialize the XML worksheet reader.' );
				}
			}
			$nodes = 0;
			while ( $reader->read() ) {
				if ( $reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row' ) {
					continue;
				}
				if ( ++$nodes > $max_physical_rows ) {
					throw new Exception( 'Worksheet ' . $sheet . ' exceeds the physical row safety limit. Split the workbook.' );
				}
				$row_number = (int) $reader->getAttribute( 'r' );
				$raw        = $reader->readOuterXml();
				if ( strlen( $raw ) > self::MAX_ROW_XML_BYTES ) {
					throw new Exception( 'Worksheet ' . $sheet . ' contains an oversized row. Split or simplify the workbook.' );
				}
				$previous = libxml_use_internal_errors( true );
				try {
					$row = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
					if ( $row === false ) {
						throw new Exception( 'Invalid worksheet row XML in ' . $sheet . '.' );
					}
					$values = array();
					$cells  = 0;
					foreach ( $row->c as $c ) {
						if ( ++$cells > self::MAX_CELLS_PER_ROW ) {
							throw new Exception( 'Worksheet ' . $sheet . ' contains a row with too many cells.' );
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
				@unlink( $temp_xml );
			}
		}
	}

	/** Backward-compatible physical-count helper. Imports should use stream_rows(). */
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

	public function rows( $sheet, $header_row = 3 ) {
		if ( ! isset( $this->sheets[ $sheet ] ) ) {
			return array();
		}
		$x = $this->xml( $this->sheets[ $sheet ] );
		if ( ! $x ) {
			return array();
		}
		$all = array();
		foreach ( $x->sheetData->row as $row ) {
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
				if ( $h !== '' ) {
					$r[ $h ] = $vals[ $i ] ?? null;
				}
			}
			if ( array_filter(
				$r,
				function ( $v ) {
					return $v !== null && $v !== '';
				}
			) ) {
				$out[] = $r;
			}
		}
		return $out;
	}
}
