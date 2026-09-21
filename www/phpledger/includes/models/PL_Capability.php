<?php
declare(strict_types=1);

/**
 * One capability in the catalogue: the unit of authorisation pl_user_can() answers about.
 *
 * `owner_type`/`owner_id` name whoever registered it — `core`, a module, or from M8 a plugin —
 * which is what makes a grant INERT rather than lost when a company switches that module off.
 * Read PL_Model's class comment for the prefix and metadata-cost rules that govern this record.
 *
 * @property int|string|null $id
 * @property string|null $capability
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $scope
 * @property string|null $label
 * @property string|null $description
 * @property string|null $registered_at
 */
class PL_Capability extends PL_Model
{
    protected static $_tablename = 'pl_capabilities';

    /** @return static|null */
    public static function findByName(string $capability)
    {
        return static::Search(['capability' => $capability]);
    }

    /** @return list<static> */
    public static function ownedBy(string $ownerType, string $ownerId = ''): array
    {
        return static::SearchMany('SELECT * FROM pl_capabilities WHERE owner_type=%s AND owner_id=%s ORDER BY capability', $ownerType, $ownerId);
    }

    public function isInstallationScope(): bool
    {
        return (string) $this->scope === 'installation';
    }

    /**
     * Is a grant of this capability effective in this company right now? A capability owned by a
     * module the company has disabled answers no; its grant rows are retained untouched.
     */
    public function isEffectiveIn(int $companyId): bool
    {
        if ((string) $this->owner_type === 'core' || (string) $this->owner_id === '') {
            return true;
        }
        return pl_module_state($companyId, (string) $this->owner_id)['enabled'];
    }

    public function __toString(): string
    {
        return 'PL_Capability#' . (string) ($this->id ?? 'new');
    }
}
