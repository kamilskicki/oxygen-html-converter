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
