<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Session\SwooleTableSessionPersistence;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;

beforeEach(function (): void {
    // Reset shared table between tests
    SwooleTableSessionPersistence::resetSharedTable();
    $this->persistence = new SwooleTableSessionPersistence();
});

describe('SwooleTableSessionPersistence', function (): void {
    describe('initializeSessionFromRequest', function (): void {
        it('creates a new session for request without cookie', function (): void {
            $request = new ServerRequest();

            $session = $this->persistence->initializeSessionFromRequest($request);

            expect($session->getId())->not->toBeEmpty();
            expect($session->toArray())->toBe([]);
        });

        it('loads existing session from cookie', function (): void {
            // First, create a session
            $request1 = new ServerRequest();
            $session1 = $this->persistence->initializeSessionFromRequest($request1);
            $session1->set('key', 'value');

            // Persist it
            $response = $this->persistence->persistSession($session1, new Response());

            // Extract session ID from cookie
            $cookie = $response->getHeaderLine('Set-Cookie');
            preg_match('/PHPSESSID=([^;]+)/', $cookie, $matches);
            $sessionId = $matches[1];

            // Create new request with the cookie
            $request2 = new ServerRequest([], [], null, 'GET', 'php://input', [], [
                'PHPSESSID' => $sessionId,
            ]);

            $session2 = $this->persistence->initializeSessionFromRequest($request2);

            expect($session2->get('key'))->toBe('value');
        });

        it('returns empty session for expired cookie', function (): void {
            // Create persistence with very short TTL
            $persistence = new SwooleTableSessionPersistence(
                cookieName: 'PHPSESSID',
                sessionTtl: 1, // 1 second
            );

            $request1 = new ServerRequest();
            $session1 = $persistence->initializeSessionFromRequest($request1);
            $session1->set('key', 'value');

            $response = $persistence->persistSession($session1, new Response());

            // Extract session ID
            $cookie = $response->getHeaderLine('Set-Cookie');
            preg_match('/PHPSESSID=([^;]+)/', $cookie, $matches);
            $sessionId = $matches[1];

            // Wait for expiration
            sleep(2);

            // Try to load expired session
            $request2 = new ServerRequest([], [], null, 'GET', 'php://input', [], [
                'PHPSESSID' => $sessionId,
            ]);

            $session2 = $persistence->initializeSessionFromRequest($request2);

            expect($session2->get('key'))->toBeNull();
        });
    });

    describe('persistSession', function (): void {
        it('sets session cookie in response', function (): void {
            $request = new ServerRequest();
            $session = $this->persistence->initializeSessionFromRequest($request);

            $response = $this->persistence->persistSession($session, new Response());

            $cookie = $response->getHeaderLine('Set-Cookie');
            expect($cookie)->toContain('PHPSESSID=');
            expect($cookie)->toContain('HttpOnly');
            expect($cookie)->toContain('SameSite=Lax');
        });

        it('stores session data', function (): void {
            $request = new ServerRequest();
            $session = $this->persistence->initializeSessionFromRequest($request);
            $session->set('user', ['id' => 1, 'name' => 'Test']);

            $this->persistence->persistSession($session, new Response());

            // Load session again
            $request2 = new ServerRequest([], [], null, 'GET', 'php://input', [], [
                'PHPSESSID' => $session->getId(),
            ]);
            $session2 = $this->persistence->initializeSessionFromRequest($request2);

            expect($session2->get('user'))->toBe(['id' => 1, 'name' => 'Test']);
        });
    });

    describe('garbageCollect', function (): void {
        it('removes expired sessions', function (): void {
            // Create persistence with very short TTL
            $persistence = new SwooleTableSessionPersistence(
                cookieName: 'PHPSESSID',
                sessionTtl: 1,
            );

            $request = new ServerRequest();
            $session = $persistence->initializeSessionFromRequest($request);
            $session->set('key', 'value');
            $persistence->persistSession($session, new Response());

            // Wait for expiration
            sleep(2);

            $deleted = $persistence->garbageCollect();

            expect($deleted)->toBe(1);
        });
    });

    describe('shared table', function (): void {
        it('uses shared table across instances', function (): void {
            // Create first instance and store data
            $persistence1 = new SwooleTableSessionPersistence(
                table: SwooleTableSessionPersistence::getSharedTable(),
            );

            $request = new ServerRequest();
            $session1 = $persistence1->initializeSessionFromRequest($request);
            $session1->set('shared', 'data');
            $persistence1->persistSession($session1, new Response());

            // Create second instance with shared table
            $persistence2 = new SwooleTableSessionPersistence(
                table: SwooleTableSessionPersistence::getSharedTable(),
            );

            $request2 = new ServerRequest([], [], null, 'GET', 'php://input', [], [
                'PHPSESSID' => $session1->getId(),
            ]);
            $session2 = $persistence2->initializeSessionFromRequest($request2);

            expect($session2->get('shared'))->toBe('data');
        });
    });
});
