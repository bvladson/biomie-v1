<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Config;
use BIomie\Support\HardDataExclusionPolicy;
use BIomie\Support\LicitacaoSeller;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

/**
 * INTEGRIDADE COMERCIAL — vigia cada causa do incidente de 29/09/2026.
 *
 * Naquele dia o BIomie mostrou R$ 0,00 para vendas que existiam, por quatro
 * caminhos diferentes. Cada um vira um alarme aqui:
 *
 *   exclusao_licitacao      regra de exclusão dura mirando o vendedor "Licitação"
 *                           ou com motivo de licitação (MAG Filial, 08/06/2026)   CRÍTICO
 *   ferramenta_remocao      a ferramenta que criou essa exclusão voltou ao código CRÍTICO
 *   todas_desligado         "Todas as operações" desligado: empresa volta a abrir
 *                           num canal só e esconde o outro (caso Uni Design)        CRÍTICO
 *   venda_omie_fora_do_bi   NF que está no bronze (veio do Omie) e não chegou à
 *                           base canônica — qualquer descarte silencioso           ALERTA
 *   exclusao_vendedor       regra que descarta um vendedor inteiro                  ALERTA
 *   nf_nunca_classificada   NF comercial que nunca passou pelas regras de canal     ALERTA
 *   pedido_nf_divergente    pedido com canal diferente da própria NF                ALERTA
 *   acesso_um_canal         usuário que só enxerga um canal numa empresa           ALERTA
 *   vendas_sem_vendedor     fila de saneamento (métrica; não esconde valor)          INFO
 *
 * Somente leitura. Nunca lança (CheckInterface): falha vira 'unknown'.
 */
final class CommercialIntegrityHealthCheck implements CheckInterface
{
    /** Motivo das regras criadas em 08/06/2026 para remover a licitação da Filial. */
    private const LICITACAO_REASON_LIKE = '%licita%';
    /** A ferramenta que gerou a remoção de 08/06/2026. Não pode voltar. */
    private const PROHIBITED_TOOL = 'tools/dev/remove_licitacao_data.php';
    /** Janela do bronze × canônica e folga para o sync gravar a canônica. */
    private const GAP_WINDOW_DAYS = 60;
    private const GAP_GRACE_HOURS = 2;

    public function __construct(
        private readonly ?PDO $pdo,
        private readonly string $rootPath = '',
        private readonly ?bool $mixedEnabled = null,
    ) {}

    public function id(): string    { return 'commercial_integrity'; }
    public function label(): string { return 'Integridade Comercial'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        try {
            $findings = $this->evaluate();
        } catch (Throwable $e) {
            return SystemHealthCheck::unknown($this->id(), $this->label(), $e, $this->ms($start));
        }

        $metrics = [];
        $actions = [];
        $criticos = [];
        $alertas = [];
        foreach ($findings as $f) {
            $metrics[$f['code']] = $f['count'];
            if ($f['severity'] === 'critical') {
                $criticos[] = $f['title'];
                $actions[] = $f['action'];
            } elseif ($f['severity'] === 'warning') {
                $alertas[] = $f['title'];
                $actions[] = $f['action'];
            }
        }

        if ($criticos !== []) {
            return SystemHealthCheck::critical($this->id(), $this->label(),
                implode(' · ', $criticos), $metrics, $actions, $this->ms($start));
        }
        if ($alertas !== []) {
            return SystemHealthCheck::warning($this->id(), $this->label(),
                implode(' · ', $alertas), $metrics, $actions, $this->ms($start));
        }

        return SystemHealthCheck::ok($this->id(), $this->label(),
            'Nenhuma venda escondida ou descartada: exclusões, canais, classificação e acessos em ordem.',
            $metrics, $this->ms($start));
    }

    /**
     * Um achado por verificação (inclusive os zerados, para as métricas).
     *
     * @return list<array{code:string,severity:string,count:int,title:string,action:string,details:list<string>}>
     */
    public function evaluate(): array
    {
        $out = [];
        $out[] = $this->prohibitedTool();
        $out[] = $this->mixedContext();
        if ($this->pdo === null) {
            return $out;
        }
        array_push($out, ...$this->exclusionRules());
        $out[] = $this->omieOutsideBi();
        $out[] = $this->neverClassified();
        $out[] = $this->orderInvoiceDivergence();
        $out[] = $this->singleChannelAccess();
        $out[] = $this->salesWithoutSeller();

        return $out;
    }

    /** @return array{code:string,severity:string,count:int,title:string,action:string,details:list<string>} */
    private function finding(string $code, int $count, string $severityIfAny, string $title, string $action, array $details = []): array
    {
        return [
            'code' => $code,
            'severity' => $count > 0 ? $severityIfAny : 'ok',
            'count' => $count,
            'title' => $title,
            'action' => $action,
            'details' => array_values(array_map('strval', $details)),
        ];
    }

    private function prohibitedTool(): array
    {
        $root = $this->rootPath !== '' ? rtrim($this->rootPath, '/\\') : dirname(__DIR__, 3);
        $exists = is_file($root . '/' . self::PROHIBITED_TOOL);

        return $this->finding('ferramenta_remocao', $exists ? 1 : 0, 'critical',
            'Ferramenta de remoção de licitação presente no código',
            'Remova ' . self::PROHIBITED_TOOL . ': licitação é venda e não pode ser apagada do BIomie.',
            $exists ? [self::PROHIBITED_TOOL] : []);
    }

    private function mixedContext(): array
    {
        $enabled = $this->mixedEnabled
            ?? (bool) (new Config())->get('features.mixed_company_context_enabled', false);

        return $this->finding('todas_desligado', $enabled ? 0 : 1, 'critical',
            '"Todas as operações" desligado: empresas abrem num canal só',
            'Ligue BIOMIE_MIXED_COMPANY_CONTEXT_ENABLED (padrão ligado desde 29/09/2026).');
    }

    /** @return list<array{code:string,severity:string,count:int,title:string,action:string,details:list<string>}> */
    private function exclusionRules(): array
    {
        if (!$this->tableExists('biomie_data_exclusion_rules')) {
            return [
                $this->finding('exclusao_licitacao', 0, 'critical', '', ''),
                $this->finding('exclusao_vendedor', 0, 'warning', '', ''),
            ];
        }

        $rows = $this->pdo->query(
            "SELECT id, company_id, identifier_type, identifier_value, reason
               FROM biomie_data_exclusion_rules WHERE active = 1"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $licitacaoCodes = [];
        $licitacao = [];
        $vendedor = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            $licitacaoCodes[$cid] ??= HardDataExclusionPolicy::licitacaoSellerCodes($this->pdo, $cid);
            $isSeller = (string) $row['identifier_type'] === 'vendor_code';
            $value = trim((string) $row['identifier_value']);
            $reasonLicitacao = stripos((string) $row['reason'], 'licita') !== false;
            if (($isSeller && in_array($value, $licitacaoCodes[$cid], true)) || $reasonLicitacao) {
                $licitacao[] = sprintf('empresa %d · regra #%d %s=%s', $cid, (int) $row['id'], $row['identifier_type'], $value);
            } elseif ($isSeller) {
                $vendedor[] = sprintf('empresa %d · regra #%d vendedor %s (%s)', $cid, (int) $row['id'], $value, $row['reason']);
            }
        }

        return [
            $this->finding('exclusao_licitacao', count($licitacao), 'critical',
                count($licitacao) . ' regra(s) de exclusão dura contra licitação',
                'Desative as regras (migração 20260929150000); o código já as ignora para o vendedor Licitação, mas NFs listadas uma a uma continuam descartadas.',
                array_slice($licitacao, 0, 10)),
            $this->finding('exclusao_vendedor', count($vendedor), 'warning',
                count($vendedor) . ' regra(s) descartam um vendedor inteiro',
                'Confirme se cada vendedor excluído não tem venda real — a exclusão é silenciosa.',
                array_slice($vendedor, 0, 10)),
        ];
    }

    private function omieOutsideBi(): array
    {
        if (!$this->tableExists('biomie_omie_bronze')) {
            return $this->finding('venda_omie_fora_do_bi', 0, 'warning', '', '');
        }
        $stmt = $this->pdo->query(
            "SELECT b.company_id, COUNT(DISTINCT b.natural_key) AS nfs
               FROM biomie_omie_bronze b
               LEFT JOIN biomie_notas_fiscais_canonicas n
                 ON n.company_id = b.company_id AND n.chave_nfe = b.natural_key
              WHERE b.domain_name = 'invoices' AND b.entity_name = 'invoice'
                AND b.natural_key IS NOT NULL AND b.natural_key <> ''
                AND b.ingested_at >= NOW() - INTERVAL " . self::GAP_WINDOW_DAYS . " DAY
                AND b.ingested_at <  NOW() - INTERVAL " . self::GAP_GRACE_HOURS . " HOUR
                AND n.id IS NULL
              GROUP BY b.company_id"
        );
        $total = 0;
        $details = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $total += (int) $row['nfs'];
            $details[] = sprintf('empresa %d: %d NF(s)', (int) $row['company_id'], (int) $row['nfs']);
        }

        return $this->finding('venda_omie_fora_do_bi', $total, 'warning',
            $total . ' NF(s) do Omie (últimos ' . self::GAP_WINDOW_DAYS . ' dias) fora da base do BIomie',
            'Compare bronze × canônica; se for exclusão, revise biomie_data_exclusion_rules; senão, rode bin/omie_nfe_backfill.php no período.',
            $details);
    }

    private function neverClassified(): array
    {
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
              WHERE channel_classified_at IS NULL
                AND status_fiscal = 'autorizada' AND operation_scope = 'commercial'
                AND (channel_confidence IS NULL OR channel_confidence <> 'manual')"
        )->fetchColumn();

        return $this->finding('nf_nunca_classificada', $count, 'warning',
            $count . ' NF(s) comerciais nunca passaram pelas regras de canal',
            'Rode bin/reclassify_unclassified_invoices.php --company=all (simula) e depois --apply.');
    }

    private function orderInvoiceDivergence(): array
    {
        $count = (int) $this->pdo->query(
            "SELECT COUNT(DISTINCT p.id)
               FROM biomie_pedidos_canonicos p
               JOIN biomie_notas_fiscais_canonicas n
                 ON n.company_id = p.company_id AND n.pedido_id_omie = p.pedido_id_omie
              WHERE n.status_fiscal = 'autorizada'
                AND p.business_channel <> n.business_channel
                AND (p.channel_confidence IS NULL OR p.channel_confidence <> 'manual')
                AND (n.channel_confidence IS NULL OR n.channel_confidence <> 'manual')"
        )->fetchColumn();

        return $this->finding('pedido_nf_divergente', $count, 'warning',
            $count . ' pedido(s) com canal diferente da própria NF',
            'Rode bin/reclassify_unclassified_invoices.php --company=all --pedidos (simula) e depois --apply.');
    }

    private function singleChannelAccess(): array
    {
        $rows = $this->pdo->query(
            "SELECT u.username, uc.company_id, uc.can_varejo, uc.can_licitacao
               FROM biomie_user_companies uc
               JOIN biomie_users u ON u.id = uc.user_id AND u.is_active = 1
              WHERE (uc.can_varejo = 1) <> (uc.can_licitacao = 1)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $details = array_map(
            static fn (array $r): string => sprintf('%s · empresa %d · só %s',
                $r['username'], (int) $r['company_id'], (int) $r['can_varejo'] === 1 ? 'varejo' : 'licitação'),
            $rows,
        );

        return $this->finding('acesso_um_canal', count($rows), 'warning',
            count($rows) . ' acesso(s) enxergam só um canal numa empresa',
            'Em Admin → Acessos & Empresas, marque Varejo e Licitação — com um canal só, o outro some dos números.',
            array_slice($details, 0, 10));
    }

    private function salesWithoutSeller(): array
    {
        $column = $this->columnExists('biomie_notas_fiscais_canonicas', 'seller_source');
        $sql = $column
            ? "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
                WHERE status_fiscal = 'autorizada' AND seller_source IN ('missing', 'unresolved')"
            : "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
                WHERE status_fiscal = 'autorizada' AND " . LicitacaoSeller::missingSellerSql('nome_vendedor');
        $count = (int) $this->pdo->query($sql)->fetchColumn();

        // Fila de saneamento: o valor continua em "Todas"; não é alerta.
        return [
            'code' => 'vendas_sem_vendedor',
            'severity' => 'info',
            'count' => $count,
            'title' => $count . ' NF(s) sem vendedor ou com vendedor desconhecido',
            'action' => 'Atribua em Admin → Vendedor ausente (muda só o BIomie).',
            'details' => [],
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
