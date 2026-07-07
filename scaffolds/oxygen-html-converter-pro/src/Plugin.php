<?php

namespace OxyHtmlConverterPro;

class Plugin
{
    private static ?Plugin $instance = null;

    public static function getInstance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('oxy_html_converter_feature_flags', [$this, 'setProFeatureFlags']);
        add_filter('oxy_html_converter_convert_response', [$this, 'appendProMetadata'], 10, 4);
    }

    public function setProFeatureFlags(array $flags): array
    {
        $flags['pro'] = true;
        $flags['pro_stub'] = true;
        $flags['component_variant_mapper'] = false;
        $flags['component_repeated_region_mapper'] = false;
        $flags['component_list_mapper'] = false;
        $flags['component_form_mapper'] = false;
        $flags['component_dynamic_data_mapper'] = false;
        $flags['component_scoped_css_mapper'] = false;
        $flags['tailwind_runtime_integration'] = false;
        $flags['windpress_integration'] = false;
        $flags['windpress_class_mode'] = false;
        $flags['windpress_cache_reset'] = false;
        $flags['dynamic_binding_mapper'] = false;
        $flags['loop_mapper'] = false;
        $flags['woocommerce_mapper'] = false;

        return $flags;
    }

    public function appendProMetadata(array $payload): array
    {
        $payload['pro'] = [
            'active' => true,
            'version' => OXY_HTML_CONVERTER_PRO_VERSION,
        ];

        return $payload;
    }
}
