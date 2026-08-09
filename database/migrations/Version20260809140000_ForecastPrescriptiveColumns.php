<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string { return '20260809140000'; }
    public function getDescription(): string { return 'Colunas prescritivas da escada de metas em intelligence_forecasts.'; }

    public function up(PDO $pdo): void
    {
        // A CAMADA PRESCRITIVA ESTAVA SENDO JOGADA FORA.
        //
        // `target_ladder.recommend()` calcula ha tempos `required_daily`,
        // `projected_level`, `recommended_next_target` e `ladder_confidence`.
        // Os quatro entram no `ForecastLinha` e no `to_metrics()` -- e o INSERT
        // de `forecast_store.persist` nunca os incluiu, porque a tabela nao
        // tinha coluna para eles.
        //
        // Resultado pratico: sobreviviam apenas dentro do `metric_json` de um
        // insight, que e narrativa POR EMPRESA e limitada as 4 leituras mais
        // relevantes da home. Por vendedor, o numero era calculado e perdido a
        // cada execucao — nenhuma tela conseguia dizer "faltam R$ X, R$ Y por
        // dia util" nem propor o proximo patamar de quem zerou a escada.
        //
        // Todas NULL: previsao antiga nao tem esses valores, e preencher com 0
        // faria "nao calculado" virar "nao precisa de nada por dia".
        $pdo->exec(
            'ALTER TABLE intelligence_forecasts '
            . 'ADD COLUMN IF NOT EXISTS required_daily DECIMAL(15,2) NULL '
            . "  COMMENT 'quanto por dia util para alcancar o desafio ativo', "
            . 'ADD COLUMN IF NOT EXISTS projected_level TINYINT UNSIGNED NULL '
            . "  COMMENT 'nivel que a projecao alcanca, se mantido o ritmo', "
            . 'ADD COLUMN IF NOT EXISTS recommended_next_target DECIMAL(15,2) NULL '
            . "  COMMENT 'proximo patamar sugerido apos zerar a escada; PROPOSTA, exige aprovacao', "
            . "ADD COLUMN IF NOT EXISTS ladder_confidence ENUM('alta','media','baixa','sem_dados') "
            . "  NOT NULL DEFAULT 'sem_dados' "
            . "  COMMENT 'confianca da recomendacao, derivada do erro historico'"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE intelligence_forecasts '
            . 'DROP COLUMN IF EXISTS required_daily, '
            . 'DROP COLUMN IF EXISTS projected_level, '
            . 'DROP COLUMN IF EXISTS recommended_next_target, '
            . 'DROP COLUMN IF EXISTS ladder_confidence'
        );
    }
};
