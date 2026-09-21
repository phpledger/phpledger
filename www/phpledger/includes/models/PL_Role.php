<?php
declare(strict_types=1);

/**
 * A role: a named set of capabilities, assigned to a person per company (B17).
 *
 * `company_id` NULL means an installation-wide role definition, available to every company; the
 * three protected system roles are exactly those. Read PL_Model's class comment for the prefix
 * and metadata-cost rules that govern this record.
 *
 * A protected system role is read-only here as well as in pl_save_role() and in the database:
 * migration 040 installs triggers that refuse an UPDATE or DELETE on one, so a model that forgot
 * to check still cannot rename Owner.
 *
 * @property int|string|null $id
 * @property int|string|null $company_id
 * @property int|string|null $scope_key
 * @property string|null $slug
 * @property string|null $name
 * @property string|null $description
 * @property int|string|null $is_system
 * @property int|string|null $revision
 * @property int|string|null $created_by
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class PL_Role extends PL_Model
{
    protected static $_tablename = 'pl_roles';

    /** @return static|null */
    public static function findBySlug(?int $companyId, string $slug)
    {
        // Search() takes a column hash or a COMPLETE query; a bare WHERE fragment is not one.
        // The installation-wide case needs IS NULL, which a hash cannot express.
        return $companyId === null
            ? static::Search('SELECT * FROM pl_roles WHERE company_id IS NULL AND slug=%s LIMIT 1', $slug)
            : static::Search(['company_id' => $companyId, 'slug' => $slug]);
    }

    /** Every role one company may assign: its own, plus the installation-wide definitions. */
    public static function forCompany(int $companyId): array
    {
        return static::SearchMany('SELECT * FROM pl_roles WHERE company_id IS NULL OR company_id=%i ORDER BY is_system DESC, name, id', $companyId);
    }

    public function isProtected(): bool
    {
        return (int) $this->is_system === 1;
    }

    public function isInstallationWide(): bool
    {
        return $this->company_id === null;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return array_map(
            static fn ($value): string => (string) $value,
            DB::queryFirstColumn(
                'SELECT c.capability FROM pl_role_capabilities rc JOIN pl_capabilities c ON c.id = rc.capability_id '
                . 'WHERE rc.role_id = %i ORDER BY c.capability',
                (int) $this->id
            )
        );
    }

    public function grants(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function memberCount(int $companyId): int
    {
        return (int) DB::queryFirstField('SELECT COUNT(*) FROM pl_company_members WHERE company_id = %i AND role_id = %i', $companyId, (int) $this->id);
    }

    public function __toString(): string
    {
        return 'PL_Role#' . (string) ($this->id ?? 'new');
    }
}
