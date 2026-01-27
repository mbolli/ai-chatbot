<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler;

use App\Infrastructure\Auth\AuthMiddleware;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handles authentication actions: login, register, logout, upgrade.
 * Returns Datastar SSE responses for reactive UI updates.
 */
final class AuthHandler implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        /** @var null|RouteResult $routeResult */
        $routeResult = $request->getAttribute(RouteResult::class);
        $routeName = $routeResult?->getMatchedRouteName() ?? '';

        return match (true) {
            str_ends_with($routeName, '.login') => $this->login($request),
            str_ends_with($routeName, '.register') => $this->register($request),
            str_ends_with($routeName, '.logout') => $this->logout($request),
            str_ends_with($routeName, '.upgrade') => $this->upgrade($request),
            str_ends_with($routeName, '.status') => $this->status($request),
            default => new EmptyResponse(404),
        };
    }

    public function login(ServerRequestInterface $request): ResponseInterface {
        $auth = AuthMiddleware::getAuthService($request);
        $session = AuthMiddleware::getSession($request);
        $data = $this->getRequestData($request);

        $email = $data['_authEmail'] ?? $data['email'] ?? '';
        $password = $data['_authPassword'] ?? $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return $this->errorResponse('Email and password required');
        }

        $result = $auth->login($session, $email, $password);

        if ($result['success']) {
            return $this->successResponse();
        }

        return $this->errorResponse($result['error'] ?? 'Login failed');
    }

    public function register(ServerRequestInterface $request): ResponseInterface {
        $auth = AuthMiddleware::getAuthService($request);
        $session = AuthMiddleware::getSession($request);
        $data = $this->getRequestData($request);

        $email = $data['_authEmail'] ?? $data['email'] ?? '';
        $password = $data['_authPassword'] ?? $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return $this->errorResponse('Email and password required');
        }

        if (mb_strlen($password) < 8) {
            return $this->errorResponse('Password must be at least 8 characters');
        }

        $result = $auth->register($session, $email, $password);

        if ($result['success']) {
            return $this->successResponse();
        }

        return $this->errorResponse($result['error'] ?? 'Registration failed');
    }

    public function logout(ServerRequestInterface $request): ResponseInterface {
        $auth = AuthMiddleware::getAuthService($request);
        $session = AuthMiddleware::getSession($request);

        $auth->logout($session);

        // Create new guest user for continued access
        $auth->createGuestUser($session);

        return $this->successResponse();
    }

    public function upgrade(ServerRequestInterface $request): ResponseInterface {
        $auth = AuthMiddleware::getAuthService($request);
        $session = AuthMiddleware::getSession($request);
        $data = $this->getRequestData($request);

        if (!$auth->isGuest($session)) {
            return $this->errorResponse('Only guest accounts can be upgraded');
        }

        $email = $data['_authEmail'] ?? $data['email'] ?? '';
        $password = $data['_authPassword'] ?? $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return $this->errorResponse('Email and password required');
        }

        $result = $auth->upgradeGuestAccount($session, $email, $password);

        if ($result['success']) {
            return $this->successResponse();
        }

        return $this->errorResponse($result['error'] ?? 'Upgrade failed');
    }

    public function status(ServerRequestInterface $request): ResponseInterface {
        $user = AuthMiddleware::getUser($request);

        $signals = [
            '_authModal' => null,
            '_authLoading' => false,
            '_authError' => '',
            '_userEmail' => $user->email,
            '_isGuest' => $user->isGuest,
        ];

        return $this->sseResponse($this->buildPatchSignalsEvent($signals));
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestData(ServerRequestInterface $request): array {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            $data = json_decode($body, true) ?? [];

            // Datastar wraps signals in 'datastar' key
            if (isset($data['datastar'])) {
                return $data['datastar'];
            }

            return $data;
        }

        return $request->getParsedBody() ?? [];
    }

    /**
     * Return SSE response that closes modal and reloads page.
     */
    private function successResponse(): ResponseInterface {
        $events = $this->buildPatchSignalsEvent([
            '_authModal' => null,
            '_authLoading' => false,
            '_authError' => '',
            '_authEmail' => '',
            '_authPassword' => '',
        ]);

        // Reload page to get fresh user state
        $events .= $this->buildExecuteScriptEvent('window.location.reload()');

        return $this->sseResponse($events);
    }

    /**
     * Return SSE response with error message.
     */
    private function errorResponse(string $message): ResponseInterface {
        $events = $this->buildPatchSignalsEvent([
            '_authLoading' => false,
            '_authError' => $message,
        ]);

        return $this->sseResponse($events);
    }

    /**
     * Build SSE response with given event data.
     */
    private function sseResponse(string $eventData): ResponseInterface {
        $response = new Response();
        $response = $response->withHeader('Content-Type', 'text/event-stream');
        $response = $response->withHeader('Cache-Control', 'no-cache');

        $response->getBody()->write($eventData);

        return $response;
    }

    /**
     * Build a Datastar patch-signals SSE event.
     *
     * @param array<string, mixed> $signals
     */
    private function buildPatchSignalsEvent(array $signals): string {
        $data = json_encode($signals, JSON_THROW_ON_ERROR);

        return "event: datastar-patch-signals\ndata: signals {$data}\n\n";
    }

    /**
     * Build a Datastar execute-script SSE event.
     */
    private function buildExecuteScriptEvent(string $script): string {
        return "event: datastar-execute-script\ndata: script {$script}\n\n";
    }
}
