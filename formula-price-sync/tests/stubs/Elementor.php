<?php

namespace Elementor;

abstract class Widget_Base {
	private array $test_settings = array();

	public function __construct( $data = array(), $args = null ) {
		$this->test_settings = is_array( $data ) ? $data : array();
	}

	public function get_settings_for_display() {
		return $this->test_settings;
	}

	public function set_test_settings( array $settings ): void {
		$this->test_settings = $settings;
	}

	public function start_controls_section( $id, $args ): void {}
	public function add_control( $id, $args ): void {}
	public function add_group_control( $type, $args ): void {}
	public function add_responsive_control( $id, $args ): void {}
	public function end_controls_section(): void {}
}
