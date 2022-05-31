<?php

namespace OnDemandRevalidation;

class Settings
{
    public static $optionName = 'on-demand-revalidation';

    public static function get()
    {
        return get_option(self::$optionName);
    }
}
