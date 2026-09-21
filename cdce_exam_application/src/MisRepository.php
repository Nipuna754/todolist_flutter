<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * Read-only lookups against the CDCE MIS (http://10.40.129.2/cdcesys/mis_1/).
 *
 * The MIS schema is not fixed here: the table, the four columns and the
 * eligibility filter all come from config, because this tool must keep working
 * when the MIS is reorganised between intakes. Identifiers from config are
 * whitelisted before they reach the query, and every value is bound.
 */
final class MisRepository
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** Reserved for the NIC variant placeholders, so config cannot rebind them. */
    private const NIC_PARAM_PREFIX = 'nic_variant_';

    /** @var list<string> */
    private const LOGICAL_COLUMNS = ['registration_no', 'nic', 'name_with_initials', 'name_in_full'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Config $config,
    ) {
    }

    public static function connect(Config $config): self
    {
        $pdo = new \PDO(
            (string) $config->require('mis.dsn'),
            $config->string('mis.username'),
            $config->string('mis.password'),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => $config->int('mis.timeout_seconds', 10),
            ]
        );

        return new self($pdo, $config);
    }

    /**
     * The single student eligible to sit the configured examination under this
     * NIC number, or null if the MIS holds no such candidate.
     *
     * @throws AmbiguousStudentException when the NIC matches more than one
     *         eligible candidate, which means the MIS needs correcting by hand.
     */
    public function findEligibleStudent(Nic $nic): ?StudentRecord
    {
        $columns = $this->columnMap();
        $table = $this->identifier((string) $this->config->require('mis.table'), 'mis.table');

        $select = [];
        foreach ($columns as $logical => $physical) {
            $select[] = sprintf('%s AS %s', $physical, $this->quote($logical));
        }

        $variants = $nic->lookupVariants();
        $placeholders = [];
        $params = [];
        foreach ($variants as $i => $variant) {
            $placeholders[] = ':' . self::NIC_PARAM_PREFIX . $i;
            $params[self::NIC_PARAM_PREFIX . $i] = $variant;
        }

        // The MIS holds NIC numbers with stray spaces and dashes, so compare a
        // normalised form on both sides rather than the raw column.
        $nicExpression = sprintf(
            "UPPER(REPLACE(REPLACE(%s, ' ', ''), '-', ''))",
            $columns['nic']
        );

        $where = [$nicExpression . ' IN (' . implode(', ', $placeholders) . ')'];

        $eligibility = trim($this->config->string('mis.eligibility.sql'));
        if ($eligibility !== '') {
            $where[] = '(' . $eligibility . ')';
            foreach ($this->config->array('mis.eligibility.params') as $name => $value) {
                $name = (string) $name;
                if (str_starts_with($name, self::NIC_PARAM_PREFIX)) {
                    throw new \RuntimeException(sprintf(
                        'Eligibility parameter %s collides with the reserved prefix %s.',
                        $name,
                        self::NIC_PARAM_PREFIX
                    ));
                }
                $params[$name] = $value;
            }
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s LIMIT 2',
            implode(', ', $select),
            $table,
            implode(' AND ', $where)
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        if ($rows === []) {
            return null;
        }

        if (count($rows) > 1) {
            throw new AmbiguousStudentException(
                'More than one eligible candidate is recorded under this National ID number.'
            );
        }

        return StudentRecord::fromRow($rows[0]);
    }

    /**
     * Physical column names, keyed by the logical names the rest of the code
     * uses.
     *
     * @return array<string, string>
     */
    private function columnMap(): array
    {
        $configured = $this->config->array('mis.columns');
        $map = [];

        foreach (self::LOGICAL_COLUMNS as $logical) {
            $physical = $configured[$logical] ?? null;
            if (!is_string($physical) || $physical === '') {
                throw new \RuntimeException('Missing required configuration key: mis.columns.' . $logical);
            }
            $map[$logical] = $this->identifier($physical, 'mis.columns.' . $logical);
        }

        return $map;
    }

    /**
     * Config is trusted less than code: a table or column name is accepted only
     * if it is a plain identifier, so nothing can be smuggled into the SQL.
     */
    private function identifier(string $name, string $key): string
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new \RuntimeException(sprintf('Configuration value %s is not a valid SQL identifier: %s', $key, $name));
        }

        return $this->quote($name);
    }

    private function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }
}
