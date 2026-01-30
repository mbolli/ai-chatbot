<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\User;

/**
 * Repository interface for User persistence.
 *
 * This extends the concept from Mezzio\Authentication\UserRepositoryInterface
 * but provides domain-specific methods.
 */
interface UserRepositoryInterface {
    /**
     * Find a user by their ID.
     */
    public function findById(int $id): ?User;

    /**
     * Find a user by their email address.
     */
    public function findByEmail(string $email): ?User;

    /**
     * Authenticate a user by email and password.
     * Returns the User if credentials are valid, null otherwise.
     */
    public function authenticate(string $email, string $password): ?User;

    /**
     * Create a new user with email and password.
     *
     * @throws \RuntimeException If email already exists
     */
    public function createUser(string $email, string $password): User;

    /**
     * Create or get a guest user.
     * Guest users have a unique ID but are marked as guests.
     */
    public function createGuestUser(): User;

    /**
     * Upgrade a guest user to a registered user.
     *
     * @throws \RuntimeException If guest not found or email already exists
     */
    public function upgradeGuestUser(int $guestId, string $email, string $password): User;

    /**
     * Delete orphaned guest users (guests with no chats).
     * Returns the number of deleted users.
     */
    public function cleanupOrphanedGuests(): int;

    /**
     * Update a user's password.
     */
    public function updatePassword(int $userId, string $newPassword): void;

    /**
     * Delete a user by ID.
     */
    public function delete(int $userId): void;
}
