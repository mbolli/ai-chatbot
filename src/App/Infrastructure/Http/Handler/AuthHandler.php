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
use starfederation\datastar\events\ExecuteScript;
use starfederation\datastar\events\PatchSignals;
use starfederation\datastar\ServerSentEventGenerator;

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

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

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

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

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

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

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

        $signalsEvent = new PatchSignals([
            '_authModal' => null,
            '_authLoading' => false,
            '_authError' => '',
            '_userEmail' => $user->email,
            '_isGuest' => $user->isGuest,
        ]);

        return $this->sseResponse($signalsEvent->getOutput());
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
        $signalsEvent = new PatchSignals([
            '_authModal' => null,
            '_authLoading' => false,
            '_authError' => '',
            '_authEmail' => '',
            '_authPassword' => '',
        ]);

        // Reload page to get fresh user state
        $scriptEvent = new ExecuteScript('window.location.reload()');

        return $this->sseResponse($signalsEvent->getOutput() . $scriptEvent->getOutput());
    }

    /**
     * Return SSE response with error message.
     */
    private function errorResponse(string $message): ResponseInterface {
        $signalsEvent = new PatchSignals([
            '_authLoading' => false,
            '_authError' => $message,
        ]);

        return $this->sseResponse($signalsEvent->getOutput());
    }

    /**
     * Build SSE response with given event data.
     */
    private function sseResponse(string $eventData): ResponseInterface {
        $response = new Response();
        foreach (ServerSentEventGenerator::headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write($eventData);

        return $response;
    }
}
