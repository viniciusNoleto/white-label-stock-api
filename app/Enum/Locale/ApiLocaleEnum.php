<?php

namespace App\Enum\Locale;

/**
 * Idiomas das mensagens devolvidas pela API: a chave é a do front
 * (`pt-br`, `es-mx`, `en-us`), o método aponta para a pasta de `lang/`.
 */
enum ApiLocaleEnum: string {
    case PT_BR = 'pt-br';
    case ES_MX = 'es-mx';
    case EN_US = 'en-us';

    /**
     * @return string
     */
    public function laravelLocale(): string {
        return match ($this) {
            self::PT_BR => 'pt_BR',
            self::ES_MX => 'es_MX',
            self::EN_US => 'en',
        };
    }
}
