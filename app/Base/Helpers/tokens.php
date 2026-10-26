<?php

if (!function_exists('generate_opaque_token')) {
    /**
     * Token opaco (não JWT): 32 bytes aleatórios em hex.
     *
     * @return string
     */
    function generate_opaque_token(): string {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('hash_token')) {
    /**
     * Só o sha256 do token é persistido; o valor cru vai apenas no cookie httpOnly.
     *
     * @param string $token
     * @return string
     */
    function hash_token(string $token): string {
        return hash('sha256', $token);
    }
}
