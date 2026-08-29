<?php

class OCTK_Dashboard_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		OCTK_Registry::reset_memo();
		foreach ( array_keys( OCTK_DASHBOARD_PERIODS ) as $period ) {
			delete_transient( 'octk_dashboard_widget_' . $period );
		}
		update_option( 'octk_enabled', '1' );
	}

	private function seed_click( int $hits, string $bucket = '' ): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		$dest    = octk_normalize_destination( 'https://m1.example/a' );
		$tokens  = OCTK_Registry::tokens_for( 'post', $post_id, array( $dest ) );
		$links   = octk_table_links();
		$clicks  = octk_table_clicks();
		$link_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$links} WHERE public_token = UNHEX(%s)", array_values( $tokens )[0] )
		);
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$clicks} (link_id, bucket_start_utc, hits) VALUES (%d, %s, %d)",
				$link_id,
				'' !== $bucket ? $bucket : gmdate( 'Y-m-d H:00:00' ),
				$hits
			)
		);
	}

	public function test_data_totals_and_top_rows() {
		$this->seed_click( 9 );

		$data = octk_dashboard_data( '7' );

		$this->assertSame( 9, $data->clicks );
		$this->assertCount( 1, $data->top );
		$this->assertSame( 'm1.example', $data->top[0]->destination_host );
	}

	public function test_period_bounds_respected() {
		$this->seed_click( 9 );
		$this->seed_click( 4, gmdate( 'Y-m-d 10:00:00', strtotime( '-20 days' ) ) );

		$this->assertSame( 9, octk_dashboard_data( '7' )->clicks );
		$this->assertSame( 13, octk_dashboard_data( '30' )->clicks );
	}

	public function test_today_and_yesterday_are_single_day_windows() {
		// Site tz is UTC in tests, so gmdate buckets land on the site-tz days.
		$this->seed_click( 9, gmdate( 'Y-m-d 00:00:00' ) );
		$this->seed_click( 4, gmdate( 'Y-m-d 10:00:00', strtotime( '-1 day' ) ) );
		$this->seed_click( 2, gmdate( 'Y-m-d 10:00:00', strtotime( '-2 days' ) ) );

		$this->assertSame( 9, octk_dashboard_data( 'today' )->clicks );
		$this->assertSame( 4, octk_dashboard_data( 'yesterday' )->clicks );
		$this->assertSame( 15, octk_dashboard_data( '7' )->clicks );
	}

	public function test_data_is_cached_for_ttl_per_period() {
		$this->seed_click( 9 );
		$this->assertSame( 9, octk_dashboard_data( '7' )->clicks );

		$this->seed_click( 5 );
		$this->assertSame( 9, octk_dashboard_data( '7' )->clicks, 'cached value should persist' );
		$this->assertSame( 14, octk_dashboard_data( '30' )->clicks, 'other periods cache independently' );

		delete_transient( 'octk_dashboard_widget_7' );
		$this->assertSame( 14, octk_dashboard_data( '7' )->clicks );
	}

	public function test_default_period_and_meta_override() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$this->assertSame( '7', octk_dashboard_period() );
		update_user_meta( $user, 'octk_dashboard_days', 'yesterday' );
		$this->assertSame( 'yesterday', octk_dashboard_period() );
		update_user_meta( $user, 'octk_dashboard_days', 30 ); // legacy int preference
		$this->assertSame( '30', octk_dashboard_period() );
		update_user_meta( $user, 'octk_dashboard_days', 999 );
		$this->assertSame( '7', octk_dashboard_period(), 'invalid stored value falls back' );

		wp_set_current_user( 0 );
	}

	public function test_report_url_maps_single_days_to_custom_range() {
		$this->assertStringContainsString( 'range=7', octk_dashboard_report_url( '7' ) );

		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$this->assertStringContainsString( 'range=custom&from=' . $today->format( 'Y-m-d' ) . '&to=' . $today->format( 'Y-m-d' ), octk_dashboard_report_url( 'today' ) );
		$yesterday = $today->modify( '-1 day' );
		$this->assertStringContainsString( 'range=custom&from=' . $yesterday->format( 'Y-m-d' ) . '&to=' . $yesterday->format( 'Y-m-d' ), octk_dashboard_report_url( 'yesterday' ) );
	}

	public function test_widget_output_states() {
		// Zero state.
		ob_start();
		octk_render_dashboard_widget();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'No outbound clicks recorded', $html );
		$this->assertStringContainsString( '>Today<', $html );
		$this->assertStringContainsString( '>Yesterday<', $html );

		// Disabled state.
		update_option( 'octk_enabled', '0' );
		ob_start();
		octk_render_dashboard_widget();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Tracking is currently disabled', $html );
		update_option( 'octk_enabled', '1' );

		// Data state.
		$this->seed_click( 3 );
		delete_transient( 'octk_dashboard_widget_7' );
		ob_start();
		octk_render_dashboard_widget();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'total clicks', $html );
		$this->assertStringContainsString( 'm1.example', $html );
		$this->assertStringContainsString( 'View full click report', $html );
	}
}
