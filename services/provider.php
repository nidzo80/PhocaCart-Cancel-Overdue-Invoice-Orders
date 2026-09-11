<?php

/**
 * @package     LeNix.Plugin.Task.PcpInvoiceCancel
 * @copyright   Copyright (C) LeNix Dizajn Studio. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use LeNix\Plugin\Task\PcpInvoiceCancel\Extension\PcpInvoiceCancel;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new PcpInvoiceCancel(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('task', 'pcpinvoicecancel')
                );

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase(Factory::getContainer()->get('DatabaseDriver'));

                return $plugin;
            }
        );
    }
};
