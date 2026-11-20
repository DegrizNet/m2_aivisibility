<?php
/**
 * Copyright © Degriz d.o.o. All rights reserved.
 * See LICENSE.txt for the licence.
 */
declare(strict_types=1);

namespace Magelan\AiVisibility\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Answers the fixed paths agents look for at the root of the store view by handing the request to
 * our controllers. Every other path passes through untouched.
 *
 *   /llms.txt, /llms-full.txt   llms/index
 */
class Router implements RouterInterface
{
    private const FILES = ['llms.txt' => 0, 'llms-full.txt' => 1];

    public function __construct(private readonly ActionFactory $actionFactory)
    {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if ($request->getModuleName() === 'magelan_ai') {
            return null;
        }

        $target = self::route(trim((string)$request->getPathInfo(), '/'));

        if ($target === null) {
            return null;
        }

        $request->setModuleName('magelan_ai')
            ->setControllerName($target[0])
            ->setActionName($target[1]);

        foreach ($target[2] as $name => $value) {
            $request->setParam($name, $value);
        }

        return $this->actionFactory->create(Forward::class);
    }

    /**
     * Controller, action and parameters for a path, or null when the path is not ours.
     *
     * @return array{0: string, 1: string, 2: array<string, int|string>}|null
     */
    public static function route(string $path): ?array
    {
        if (isset(self::FILES[$path])) {
            return ['llms', 'index', ['full' => self::FILES[$path]]];
        }

        return null;
    }
}
