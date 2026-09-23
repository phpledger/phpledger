<?php
declare(strict_types=1);

/**
 * A person with an account on this installation (release plan 1.2, M7).
 *
 * Read the class comment on PL_Model first: it resolves the installation prefix, and this record
 * costs one `SHOW COLUMNS` per request that uses it, so it belongs on administration screens and
 * nowhere near sign-in or posting.
 *
 * This record NEVER changes a password: pl_hash_password() and the services in
 * user_functions.php own that, so the 12-to-72-byte rule and the rehash-on-sign-in path have one
 * home. A model that could write `password_hash` would be a second one.
 *
 * The @property list is the table's columns as migration 040 leaves them. MeekroORM reads a row
 * into dynamic properties, so this block is both the documentation and what static analysis
 * checks against; a column added by a later migration is added here in the same commit.
 *
 * @property int|string|null $id
 * @property string|null $email
 * @property string|null $username
 * @property string|null $display_name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone
 * @property string|null $job_title
 * @property string|null $locale
 * @property string|null $timezone
 * @property int|string|null $must_change_password
 * @property string|null $password_changed_at
 * @property int|string|null $is_active
 * @property string|null $last_signed_in_at
 * @property string|null $deactivated_at
 * @property string|null $anonymised_at
 * @property string|null $created_at
 */
class PL_User extends PL_Model
{
    protected static $_tablename = 'pl_users';

    /** @return static|null */
    public static function findByEmail(string $email)
    {
        return static::Search(['email' => strtolower(trim($email))]);
    }

    /** @return static|null */
    public static function findByUsername(string $username)
    {
        return static::Search(['username' => strtolower(trim($username))]);
    }

    public function fullName(): string
    {
        $parts = array_filter([(string) ($this->first_name ?? ''), (string) ($this->last_name ?? '')], static fn (string $p): bool => trim($p) !== '');
        return $parts === [] ? (string) $this->display_name : implode(' ', $parts);
    }

    public function isActive(): bool
    {
        return (int) $this->is_active === 1;
    }

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    /** The role this person holds in one company, or null when they do not work there. */
    public function roleIn(int $companyId): ?PL_Role
    {
        $roleId = DB::queryFirstField('SELECT role_id FROM pl_company_members WHERE company_id = %i AND user_id = %i', $companyId, (int) $this->id);
        return $roleId === null ? null : PL_Role::Load((int) $roleId);
    }

    /** The screen-safe shape: everything but the password hash. */
    public function toArray(): array
    {
        $row = parent::toArray();
        unset($row['password_hash']);
        return $row;
    }

    public function __toString(): string
    {
        return 'PL_User#' . (string) ($this->id ?? 'new');
    }
}
