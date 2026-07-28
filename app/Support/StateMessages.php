<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * Mensagens padrao oficiais do BIomie para estados visuais.
 *
 * Centraliza textos de EmptyState, ErrorState, LoadingState
 * e estados especificos para manter consistencia visual e linguistica.
 */
final class StateMessages
{
    // ── Empty ────────────────────────────────────────────────────
    public const EMPTY_TITLE = 'Nenhum dado encontrado';
    public const EMPTY_MESSAGE = 'Nenhum dado encontrado para os filtros selecionados.';
    public const EMPTY_ICON = 'search_off';

    // ── Error ────────────────────────────────────────────────────
    public const ERROR_TITLE = 'Erro ao carregar';
    public const ERROR_MESSAGE = 'Nao foi possivel carregar esta informacao agora.';
    public const ERROR_ICON = 'error_outline';

    // ── Loading ──────────────────────────────────────────────────
    public const LOADING_TITLE = 'Carregando dados';
    public const LOADING_MESSAGE = 'Carregando dados...';
    public const LOADING_ICON = 'hourglass_top';

    // ── Sync Required ────────────────────────────────────────────
    public const SYNC_TITLE = 'Sincronizacao pendente';
    public const SYNC_MESSAGE = 'Os dados ainda nao foram sincronizados com o Omie.';
    public const SYNC_ICON = 'cloud_off';
    public const SYNC_ACTION_LABEL = 'Ver Conexao Omie';
    public const SYNC_ACTION_HREF = 'integracoes.php';

    // ── Migration Required ───────────────────────────────────────
    public const MIGRATION_TITLE = 'Dados analiticos nao preparados';
    public const MIGRATION_MESSAGE = 'Os dados analiticos ainda nao foram preparados. Execute as migrations pendentes.';
    public const MIGRATION_ICON = 'database';
    public const MIGRATION_ACTION_LABEL = 'Ver migrations';
    public const MIGRATION_ACTION_HREF = 'admin.php?tab=migrations';

    // ── Materialization Required ─────────────────────────────────
    public const MATERIALIZATION_TITLE = 'Visao analitica pendente';
    public const MATERIALIZATION_MESSAGE = 'A visao analitica ainda nao foi materializada.';
    public const MATERIALIZATION_ICON = 'build_circle';

    // ── No Permission ────────────────────────────────────────────
    public const PERMISSION_TITLE = 'Acesso restrito';
    public const PERMISSION_MESSAGE = 'Voce nao tem permissao para visualizar esta informacao.';
    public const PERMISSION_ICON = 'lock';

    // ── Table-specific empties ───────────────────────────────────
    public const EMPTY_NF = 'Nenhuma nota fiscal encontrada para os filtros selecionados.';
    public const EMPTY_PRODUCTS = 'Nenhum produto faturado no periodo.';
    public const EMPTY_CLIENTS = 'Nenhum cliente faturado no periodo.';
    public const EMPTY_SELLERS = 'Nenhum vendedor com faturamento no periodo.';
    public const EMPTY_OVERDUE = 'Nenhum cliente em atraso nos filtros atuais.';
    public const EMPTY_ORDERS = 'Nenhum pedido em carteira para o periodo.';
    public const EMPTY_CANCELLED = 'Nenhuma nota cancelada no periodo.';
    public const EMPTY_NEIGHBORHOODS = 'Nenhum bairro com venda no periodo.';
    public const EMPTY_CNAE = 'Nenhum CNAE encontrado para este recorte.';
    public const EMPTY_USERS = 'Nenhum usuario encontrado.';
    public const EMPTY_MIGRATIONS = 'Nenhuma migration encontrada.';
    public const EMPTY_LOGS = 'Nenhum log administrativo registrado.';
    public const EMPTY_CHART = 'Sem dados para montar este grafico.';
    public const EMPTY_MAP = 'Sem vendas geolocalizadas no periodo.';
    public const EMPTY_RECEIVED = 'Nao houve baixas financeiras para o periodo e filtros selecionados.';
    public const EMPTY_OPEN_TITLES = 'Nao ha vencimentos pendentes para o periodo e filtros selecionados.';
    public const EMPTY_RISK = 'Nenhum cliente aparece com atraso relevante para os filtros atuais.';
    public const EMPTY_FORECAST = 'Sincronize o financeiro ou amplie o periodo para carregar a carteira prevista.';

    // ── Cell fallbacks ───────────────────────────────────────────
    public const FALLBACK_CLIENT = 'Cliente nao informado';
    public const FALLBACK_SELLER = 'Sem vendedor';
    public const FALLBACK_DOCUMENT = 'Sem documento';
    public const FALLBACK_DATE = 'Sem data';
    public const FALLBACK_STATUS = 'Status nao informado';
    public const FALLBACK_PRODUCT = 'Produto nao informado';
    public const FALLBACK_NEIGHBORHOOD = 'Bairro nao informado';
    public const FALLBACK_CNAE = 'CNAE nao informado';
    public const FALLBACK_REASON = 'Motivo nao informado';
    public const FALLBACK_EMPTY = "\xE2\x80\x94"; // em-dash
}
