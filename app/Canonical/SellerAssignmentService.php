<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalJsonCodec;
use BIomie\Support\ChannelClassifierService;
use BIomie\Support\LicitacaoSeller;
use InvalidArgumentException;
use PDO;

/**
 * VENDEDOR ATRIBUÍDO NO BIOMIE — para NF/pedido que chegou do Omie sem vendedor.
 *
 * Muda só o BI, nunca o Omie. O usuário escolhe entre os vendedores cadastrados
 * no Omie daquela empresa (biomie_vendedores_omie). Efeitos:
 *   - a linha canônica passa a ter o vendedor escolhido (filtros por vendedor,
 *     ranking, metas e marts o enxergam);
 *   - o canal segue a regra oficial ({@see LicitacaoSeller}): atribuir o vendedor
 *     "Licitação" leva a licitação; qualquer outro, a varejo. A origem do canal
 *     continua a da regra (seller_regular/seller_licitacao) e a origem do
 *     VENDEDOR vai para a coluna própria seller_source = 'bi';
 *   - a atribuição só redistribui: "Todas as operações" não muda (sai de "sem
 *     vendedor", entra no vendedor escolhido; no máximo troca varejo↔licitação);
 *   - a escrita canônica reaplica a atribuição a cada sync ENQUANTO o Omie
 *     continuar sem vendedor. Se o Omie passar a ter vendedor, o Omie vence e a
 *     atribuição fica 'superada' (CanonicalWriteRepository::applySellerAssignment).
 *   - o vendedor original (o que o Omie mandou) fica guardado; remover a
 *     atribuição o devolve.
 */
final class SellerAssignmentService
{
    public const TABLE = 'biomie_seller_assignments';
    private const TYPES = ['nf', 'pedido'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $companyId,
    ) {
        if ($companyId <= 0) {
            throw new InvalidArgumentException('Empresa inválida.');
        }
    }

    public static function tableReady(PDO $pdo): bool
    {
        static $ready = [];
        $key = spl_object_id($pdo);
        if (!isset($ready[$key])) {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'"
            );
            $ready[$key] = $stmt !== false && (int) $stmt->fetchColumn() > 0;
        }

        return $ready[$key];
    }

    /**
     * Atribuições ativas da empresa, indexadas por "tipo|chave".
     *
     * @return array<string,array{id:int,codigo_vendedor_omie:string,nome_vendedor:string}>
     */
    public static function activeFor(PDO $pdo, int $companyId): array
    {
        if (!self::tableReady($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id, entity_type, entity_key, codigo_vendedor_omie, nome_vendedor
               FROM ' . self::TABLE . "
              WHERE company_id = :cid AND status = 'ativa'"
        );
        $stmt->execute(['cid' => $companyId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['entity_type'] . '|' . $row['entity_key']] = [
                'id' => (int) $row['id'],
                'codigo_vendedor_omie' => (string) $row['codigo_vendedor_omie'],
                'nome_vendedor' => (string) $row['nome_vendedor'],
            ];
        }

        return $map;
    }

    /** O Omie passou a informar vendedor: ele vence, a atribuição do BI sai de cena. */
    public static function markSuperseded(PDO $pdo, int $assignmentId, string $omieSeller): void
    {
        $pdo->prepare(
            'UPDATE ' . self::TABLE . "
                SET status = 'superada', closed_at = NOW(),
                    closed_reason = :reason
              WHERE id = :id AND status = 'ativa'"
        )->execute([
            'id' => $assignmentId,
            'reason' => mb_substr('Omie passou a informar o vendedor: ' . $omieSeller, 0, 255),
        ]);
    }

    /**
     * Vendedores cadastrados no Omie para a empresa (as opções da tela).
     *
     * @return list<array{codigo:string,nome:string,licitacao:bool}>
     */
    public function sellers(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT codigo_omie, nome FROM biomie_vendedores_omie
              WHERE company_id = :cid AND ativo = 1
                AND codigo_omie <> '' AND codigo_omie <> '0'
              ORDER BY nome"
        );
        $stmt->execute(['cid' => $this->companyId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'codigo' => (string) $row['codigo_omie'],
                'nome' => (string) $row['nome'],
                'licitacao' => LicitacaoSeller::isLicitacaoName((string) $row['nome']),
            ];
        }

        return $out;
    }

    /**
     * NFs e pedidos sem vendedor no Omie, com a atribuição ativa quando houver.
     *
     * @return list<array<string,mixed>>
     */
    public function pending(): array
    {
        $join = self::tableReady($this->pdo);
        $nfMissing = LicitacaoSeller::missingSellerSql('n.nome_vendedor');
        $pedMissing = LicitacaoSeller::missingSellerSql('p.nome_vendedor');
        $nfAssign = $join
            ? 'LEFT JOIN ' . self::TABLE . " a ON a.company_id = n.company_id AND a.entity_type = 'nf'
                    AND a.entity_key = n.nota_key AND a.status = 'ativa'"
            : '';
        $pedAssign = $join
            ? 'LEFT JOIN ' . self::TABLE . " a ON a.company_id = p.company_id AND a.entity_type = 'pedido'
                    AND a.entity_key = CAST(p.pedido_id_omie AS CHAR) AND a.status = 'ativa'"
            : '';
        $aCols = $join
            ? 'a.id AS atribuicao_id, a.codigo_vendedor_omie AS atribuido_codigo, a.nome_vendedor AS atribuido_nome,
               a.vendedor_original_codigo, a.motivo, a.created_at AS atribuido_em'
            : 'NULL AS atribuicao_id, NULL AS atribuido_codigo, NULL AS atribuido_nome,
               NULL AS vendedor_original_codigo, NULL AS motivo, NULL AS atribuido_em';
        $aWhere = $join ? 'a.id IS NOT NULL OR ' : '';

        // Situação: 'bi' (atribuído aqui), 'unresolved' (Omie mandou código fora
        // do cadastro — "vendedor desconhecido") ou 'missing' (Omie sem vendedor).
        $situacao = static fn (string $alias): string => ($join ? 'CASE WHEN a.id IS NOT NULL THEN \'bi\' ' : 'CASE ')
            . "WHEN COALESCE(TRIM({$alias}.codigo_vendedor_omie), '') NOT IN ('', '0') THEN 'unresolved' ELSE 'missing' END";
        $sql = "SELECT 'nf' AS tipo, n.nota_key AS chave, n.numero_nf AS documento, n.data_emissao,
                       n.nome_cliente, n.valor_base_comercial_nf AS valor, n.status_fiscal AS status,
                       n.codigo_vendedor_omie, n.nome_vendedor, n.business_channel, n.visibility_rule_source,
                       " . $situacao('n') . " AS situacao,
                       {$aCols}
                  FROM biomie_notas_fiscais_canonicas n {$nfAssign}
                 WHERE n.company_id = :cid1 AND ({$aWhere}{$nfMissing})
                UNION ALL
                SELECT 'pedido', CAST(p.pedido_id_omie AS CHAR), p.codigo_pedido, p.data_emissao,
                       p.nome_cliente, p.valor_mercadorias, p.status_comercial,
                       p.codigo_vendedor_omie, p.nome_vendedor, p.business_channel, p.visibility_rule_source,
                       " . $situacao('p') . ",
                       {$aCols}
                  FROM biomie_pedidos_canonicos p {$pedAssign}
                 WHERE p.company_id = :cid2 AND ({$aWhere}{$pedMissing})
                 ORDER BY data_emissao DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['cid1' => $this->companyId, 'cid2' => $this->companyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Atribui (ou troca) o vendedor de uma NF/pedido sem vendedor no Omie.
     *
     * @return array{tipo:string,chave:string,documento:string,data_emissao:?string,vendedor:string,canal:string,origem:string}
     */
    public function assign(string $type, string $key, string $sellerCode, string $motivo, ?int $actorId): array
    {
        $this->assertReady();
        [$type, $key] = $this->normalizeTarget($type, $key);
        $sellerCode = trim($sellerCode);
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new InvalidArgumentException('Informe o motivo da atribuição (fica registrado na auditoria).');
        }

        $seller = $this->pdo->prepare(
            'SELECT codigo_omie, nome FROM biomie_vendedores_omie
              WHERE company_id = :cid AND codigo_omie = :cod AND ativo = 1 LIMIT 1'
        );
        $seller->execute(['cid' => $this->companyId, 'cod' => $sellerCode]);
        $sellerRow = $seller->fetch(PDO::FETCH_ASSOC);
        if (!is_array($sellerRow) || $sellerCode === '' || $sellerCode === '0') {
            throw new InvalidArgumentException('Vendedor não encontrado no cadastro do Omie desta empresa.');
        }

        $this->pdo->beginTransaction();
        try {
            $row = $this->canonicalRow($type, $key, true);
            $current = $this->assignmentRow($type, $key, true);
            $active = is_array($current) && $current['status'] === 'ativa';
            if (!$active && !LicitacaoSeller::isMissingSeller((string) $row['nome_vendedor'])) {
                throw new InvalidArgumentException(
                    'Esta operação já tem vendedor no Omie (' . $row['nome_vendedor'] . '). A atribuição no BI é só para operações sem vendedor.'
                );
            }

            // O original é o que o Omie mandou: numa troca de atribuição, preserva
            // o original gravado na primeira vez.
            $originalCode = $active ? $current['vendedor_original_codigo'] : (string) $row['codigo_vendedor_omie'];
            $originalName = $active ? $current['vendedor_original_nome'] : (string) $row['nome_vendedor'];

            $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . "
                    (company_id, entity_type, entity_key, documento, codigo_vendedor_omie, nome_vendedor,
                     vendedor_original_codigo, vendedor_original_nome, motivo, status, created_by, updated_by)
                 VALUES (:cid, :type, :chave, :doc, :cod, :nome, :ocod, :onome, :motivo, 'ativa', :by, :by2)
                 ON DUPLICATE KEY UPDATE
                    codigo_vendedor_omie = VALUES(codigo_vendedor_omie),
                    nome_vendedor = VALUES(nome_vendedor),
                    vendedor_original_codigo = VALUES(vendedor_original_codigo),
                    vendedor_original_nome = VALUES(vendedor_original_nome),
                    motivo = VALUES(motivo), status = 'ativa', updated_by = VALUES(updated_by),
                    closed_at = NULL, closed_reason = NULL"
            )->execute([
                'cid' => $this->companyId, 'type' => $type, 'chave' => $key,
                'doc' => (string) $row['documento'],
                'cod' => (string) $sellerRow['codigo_omie'], 'nome' => (string) $sellerRow['nome'],
                'ocod' => $originalCode, 'onome' => $originalName,
                'motivo' => mb_substr($motivo, 0, 255), 'by' => $actorId, 'by2' => $actorId,
            ]);

            $this->setCanonicalSeller($type, $key, (string) $sellerRow['codigo_omie'], (string) $sellerRow['nome']);
            $visibility = $this->writeRepository()->reclassifyOne($type, $key);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        (new ChannelClassifierService($this->pdo, $this->companyId))->propagateRelatedChannels(false);

        return [
            'tipo' => $type,
            'chave' => $key,
            'documento' => (string) $row['documento'],
            'data_emissao' => $row['data_emissao'] !== null ? (string) $row['data_emissao'] : null,
            'vendedor' => (string) $sellerRow['nome'],
            'canal' => (string) ($visibility['channel'] ?? ''),
            'origem' => (string) ($visibility['source'] ?? ''),
        ];
    }

    /**
     * Desfaz a atribuição: a linha canônica volta ao vendedor que o Omie mandou.
     *
     * @return array{tipo:string,chave:string,documento:string,data_emissao:?string,vendedor:string,canal:string,origem:string}
     */
    public function remove(string $type, string $key, ?int $actorId): array
    {
        $this->assertReady();
        [$type, $key] = $this->normalizeTarget($type, $key);

        $this->pdo->beginTransaction();
        try {
            $current = $this->assignmentRow($type, $key, true);
            if (!is_array($current) || $current['status'] !== 'ativa') {
                throw new InvalidArgumentException('Não há atribuição ativa para esta operação.');
            }
            $row = $this->canonicalRow($type, $key, true);
            $originalCode = (string) ($current['vendedor_original_codigo'] ?? '');
            $originalName = (string) ($current['vendedor_original_nome'] ?? '') ?: 'Sem vendedor';

            $this->pdo->prepare(
                'UPDATE ' . self::TABLE . "
                    SET status = 'removida', closed_at = NOW(), updated_by = :by,
                        closed_reason = 'Removida no BIomie'
                  WHERE id = :id"
            )->execute(['id' => $current['id'], 'by' => $actorId]);

            // Só devolve se a linha ainda tem o vendedor atribuído (um sync com
            // vendedor do Omie já teria vencido e não deve ser desfeito).
            if ((string) $row['codigo_vendedor_omie'] === (string) $current['codigo_vendedor_omie']) {
                $this->setCanonicalSeller($type, $key, $originalCode, $originalName);
            }
            $visibility = $this->writeRepository()->reclassifyOne($type, $key);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        (new ChannelClassifierService($this->pdo, $this->companyId))->propagateRelatedChannels(false);

        return [
            'tipo' => $type,
            'chave' => $key,
            'documento' => (string) $row['documento'],
            'data_emissao' => $row['data_emissao'] !== null ? (string) $row['data_emissao'] : null,
            'vendedor' => $originalName,
            'canal' => (string) ($visibility['channel'] ?? ''),
            'origem' => (string) ($visibility['source'] ?? ''),
        ];
    }

    private function assertReady(): void
    {
        if (!self::tableReady($this->pdo)) {
            throw new \RuntimeException('Tabela de atribuições ausente: aplique a migração 20260929140000.');
        }
    }

    /** @return array{0:string,1:string} */
    private function normalizeTarget(string $type, string $key): array
    {
        $type = strtolower(trim($type));
        $key = trim($key);
        if (!in_array($type, self::TYPES, true) || $key === '') {
            throw new InvalidArgumentException('Informe tipo (nf ou pedido) e a chave da operação.');
        }
        if ($type === 'pedido' && preg_match('/^\d+$/', $key) !== 1) {
            throw new InvalidArgumentException('Chave de pedido inválida.');
        }

        return [$type, $key];
    }

    /** @return array<string,mixed> */
    private function canonicalRow(string $type, string $key, bool $forUpdate): array
    {
        $sql = $type === 'nf'
            ? 'SELECT numero_nf AS documento, data_emissao, codigo_vendedor_omie, nome_vendedor
                 FROM biomie_notas_fiscais_canonicas WHERE company_id = :cid AND nota_key = :chave'
            : 'SELECT codigo_pedido AS documento, data_emissao, codigo_vendedor_omie, nome_vendedor
                 FROM biomie_pedidos_canonicos WHERE company_id = :cid AND pedido_id_omie = :chave';
        $stmt = $this->pdo->prepare($sql . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute(['cid' => $this->companyId, 'chave' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InvalidArgumentException('Operação não encontrada nesta empresa.');
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function assignmentRow(string $type, string $key, bool $forUpdate): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, codigo_vendedor_omie, vendedor_original_codigo, vendedor_original_nome
               FROM ' . self::TABLE . '
              WHERE company_id = :cid AND entity_type = :type AND entity_key = :chave'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['cid' => $this->companyId, 'type' => $type, 'chave' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function setCanonicalSeller(string $type, string $key, string $code, string $name): void
    {
        $sql = $type === 'nf'
            ? 'UPDATE biomie_notas_fiscais_canonicas SET codigo_vendedor_omie = :cod, nome_vendedor = :nome
                WHERE company_id = :cid AND nota_key = :chave'
            : 'UPDATE biomie_pedidos_canonicos SET codigo_vendedor_omie = :cod, nome_vendedor = :nome
                WHERE company_id = :cid AND pedido_id_omie = :chave';
        $this->pdo->prepare($sql)->execute([
            'cod' => $code, 'nome' => $name, 'cid' => $this->companyId, 'chave' => $key,
        ]);
    }

    private function writeRepository(): CanonicalWriteRepository
    {
        $pdo = $this->pdo;
        $codec = new CanonicalJsonCodec();

        return new CanonicalWriteRepository(
            static fn (): PDO => $pdo,
            static function () use ($pdo): void {
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                }
            },
            new CanonicalHashing($codec),
            $codec,
            $this->companyId,
        );
    }
}
