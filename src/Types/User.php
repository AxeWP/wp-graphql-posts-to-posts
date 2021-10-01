<?php

namespace WPGraphQLPostsToPosts\Types;

use WPGraphQLPostsToPosts\Interfaces\Hookable;
use WPGraphQLPostsToPosts\Traits\ObjectsTrait;
use WPGraphQLPostsToPosts\Types\Fields;

class User implements Hookable {

	use ObjectsTrait;

	public function register_hooks() : void {
		add_action( get_graphql_register_action(), [ $this, 'register_where_input_fields' ] );
		add_filter( 'graphql_map_input_fields_to_wp_user_query', [ $this, 'modify_query_input_fields' ], 10 );
	}

	public function register_where_input_fields() : void {
		register_graphql_field(
			'RootQueryToUserConnectionWhereArgs',
			Fields::NAME,
			[
				'type'        => [ 'list_of' => Fields::QUERY_TYPE ],
				'description' => __( 'Id', 'wp-graphql-posts-to-posts' ),
			]
		);
	}

	public function modify_query_input_fields( array $query_args ) : array {
		$p2p_connections_to_map = Fields::get_p2p_connections();

		$field_names = [];
		$include     = [];

		$post_types = self::get_post_types();

		foreach ( $post_types as $post_type ) {
			$connection_name = $post_type->name;

			$connections = array_filter( $p2p_connections_to_map, fn( $p2p_connection ) => $p2p_connection['from'] === $connection_name || $p2p_connection['to'] === $connection_name );

			foreach ( $connections as $connection ) {
				array_push( $field_names, $connection['name'] );
			}
		}

		if ( ! isset( $query_args['postToPostConnections'] ) ) {
			return $query_args;
		}

		if ( 1 === count( $query_args['postToPostConnections'] ) ) {
			$connection = $query_args['postToPostConnections'][0]['connection'];

			if ( in_array( $connection, $field_names, true ) ) {
				$connected_type                = $connection;
				$query_args['connected_type']  = sanitize_text_field( $connected_type );
				$query_args['connected_items'] = array_map( 'absint', $query_args['postToPostConnections'][0]['ids'] );
				return $query_args;
			}
		}

		foreach ( $query_args['postToPostConnections'] as $post_to_post_connection ) {
			if ( in_array( $post_to_post_connection['connection'], $field_names, true ) ) {
				$connected_type = $post_to_post_connection['connection'];

				$connected_query_args = [
					'users_per_page'         => 1000,
					'fields'                 => 'ids',
					'connected_type'         => sanitize_text_field( $connected_type ),
					'connected_items'        => array_map( 'absint', $post_to_post_connection['ids'] ),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				];

				$connected = new \WP_User_Query(
					$connected_query_args
				);

				$user_ids = $connected->get_results();

				if ( ! $user_ids ) {
					$query_args['include'] = [ 0 ];
					return $query_args;
				}

				$include = $include ? array_values( array_intersect( $include, $user_ids ) ) : $user_ids;
			}
		}

		$query_args['include'] = $include;

		return $query_args;
	}

}