<?php

namespace App\Enum\Dashboard;

/**
 * Janela do painel, em dias (terminando hoje, no fuso do negócio). Conjunto fechado de propósito:
 * cada valor é uma entrada no cache do painel, então o número de entradas é fixo.
 */
enum DashboardPeriodEnum: int {

    case WEEK = 7;

    case MONTH = 30;

    case QUARTER = 90;
}
