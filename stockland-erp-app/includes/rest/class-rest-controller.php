<?php
/**
 * REST controller source for Stockland ERP operational workflows.
 *
 * @package Stockland_ERP
 */
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Legacy filename is a loader compatibility contract; only this sniff is disabled.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for the shared Stockland REST controllers and shared branch-scope rules: namespace,
 * capability checks, and the branch-scoping check used everywhere
 * section 13 applies ("a branch employee must only access information
 * appropriate to their branch").
 */
abstract class Stockland_REST_Base {

	const NAMESPACE_V1 = STOCKLAND_REST_NAMESPACE;

	protected function require_cap( $request, $capability ) {
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error( 'rest_forbidden', 'You do not have permission to do this.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Resolves the effective branch a request is allowed to act on:
	 * - Management/Auditor may pass any branch_location_id.
	 * - Everyone else is forced onto their own assigned branch, even if
	 *   they pass a different one in the request (section 13: employee
	 *   should never be able to see or act on another branch's data).
	 */
	protected function resolve_branch_for_user( $requested_location_id ) {
		$user_id = get_current_user_id();
		$profile = Stockland_Roles::get_profile( $user_id );

		if ( ! $profile ) {
			return new WP_Error( 'no_profile', 'This user has no Stockland role/branch assignment.', array( 'status' => 403 ) );
		}

		if ( current_user_can( 'stockland_view_all_branches' ) ) {
			return $requested_location_id ? (int) $requested_location_id : null;
		}

		$allowed = method_exists( 'Stockland_Roles', 'get_location_scope' ) ? Stockland_Roles::get_location_scope( $user_id ) : array();
		if ( empty( $allowed ) ) {
			return new WP_Error( 'no_branch', 'This user is not assigned to an operational branch.', array( 'status' => 403 ) );
		}

		if ( $requested_location_id ) {
			$requested = (int) $requested_location_id;
			if ( ! in_array( $requested, $allowed, true ) ) {
				return new WP_Error( 'branch_forbidden', 'This request is outside your authorized operational scope.', array( 'status' => 403 ) );
			}
			return $requested;
		}

		return (int) $allowed[0];
	}
	protected function require_available_stock( $location_id, $sku, $quantity ) {
		$available = Stockland_DB::get_available_stock( $location_id, $sku );
		if ( $available < (float) $quantity ) {
			return new WP_Error( 'insufficient_stock', sprintf( 'Insufficient available stock for %s. Available: %s, requested: %s.', $sku, $available, $quantity ), array( 'status' => 409 ) );
		}
		return true;
	}
}

// phpcs:enable WordPress.Files.FileName.InvalidClassFileName -- End the narrowly scoped legacy filename exception.
