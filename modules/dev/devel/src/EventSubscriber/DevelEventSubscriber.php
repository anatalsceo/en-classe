<?php

/**
 * @file
 * Contains \Drupal\devel\EventSubscriber\DevelEventSubscriber.
 */

namespace Drupal\devel\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Page\DefaultHtmlPageRenderer;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;

class DevelEventSubscriber implements EventSubscriberInterface {

  /**
   * The devel.settings config object.
   *
   * @var \Drupal\Core\Config\Config;
   */
  protected $config;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a DevelEventSubscriber object.
   */
  public function __construct(ConfigFactoryInterface $config, AccountInterface $account, ModuleHandlerInterface $module_handler) {
    $this->config = $config->get('devel.settings');
    $this->account = $account;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Initializes devel module requirements.
   */
  public function onRequest(GetResponseEvent $event) {
    if (!devel_silent()) {
      if ($this->config->get('memory')) {
        global $memory_init;
        $memory_init = memory_get_usage();
      }

      if (devel_query_enabled()) {
        Database::startLog('devel');
      }

      if ($this->account->hasPermission('access devel information')) {
        devel_set_handler(devel_get_handlers());
        // We want to include the class early so that anyone may call krumo()
        // as needed. See http://krumo.sourceforge.net/
        has_krumo();

        // See http://www.firephp.org/HQ/Install.htm
        $path = NULL;
        if ((@include_once 'fb.php') || (@include_once 'FirePHPCore/fb.php')) {
          // FirePHPCore is in include_path. Probably a PEAR installation.
          $path = '';
        }
        elseif ($this->moduleHandler->moduleExists('libraries')) {
          // Support Libraries API - http://drupal.org/project/libraries
          $firephp_path = libraries_get_path('FirePHPCore');
          $firephp_path = ($firephp_path ? $firephp_path . '/lib/FirePHPCore/' : '');
          $chromephp_path = libraries_get_path('chromephp');
        }
        else {
          $firephp_path = DRUPAL_ROOT . '/libraries/FirePHPCore/lib/FirePHPCore/';
          $chromephp_path = './' . drupal_get_path('module', 'devel') . '/chromephp';
        }

        // Include FirePHP if it exists.
        if (!empty($firephp_path) && file_exists($firephp_path . 'fb.php')) {
          include_once $firephp_path . 'fb.php';
          include_once $firephp_path . 'FirePHP.class.php';
        }

        // Include ChromePHP if it exists.
        if (!empty($chromephp_path) && file_exists($chromephp_path .= '/ChromePhp.php')) {
          include_once $chromephp_path;
        }

      }
    }

    if ($this->config->get('rebuild_theme')) {
      drupal_theme_rebuild();

      // Ensure that the active theme object is cleared.
      $theme_name = \Drupal::theme()->getActiveTheme()->getName();
      \Drupal::state()->delete('theme.active_theme.' . $theme_name);
      \Drupal::theme()->resetActiveTheme();

      /** @var \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler*/
      $theme_handler = \Drupal::service('theme_handler');
      $theme_handler->refreshInfo();
      // @todo This is not needed after https://www.drupal.org/node/2330755
      $list = $theme_handler->listInfo();
      $theme_handler->addTheme($list[$theme_name]);

      if (\Drupal::service('flood')->isAllowed('devel.rebuild_theme_warning', 1)) {
        \Drupal::service('flood')->register('devel.rebuild_theme_warning');
        if (!devel_silent() && $this->account->hasPermission('access devel information')) {
          drupal_set_message(t('The theme information is being rebuilt on every request. Remember to <a href="!url">turn off</a> this feature on production websites.', array("!url" => url('admin/config/development/devel'))));
        }
      }
    }

    drupal_register_shutdown_function('devel_shutdown');
  }

  /**
   * Prevents page redirection so that the developer can see the intermediate debug data.
   * @param FilterResponseEvent $event
   */
  public function onResponse(FilterResponseEvent $event) {
    $response = $event->getResponse();
    if ($response instanceOf RedirectResponse && !devel_silent()) {
      if ($this->account->hasPermission('access devel information') && $this->config->get('redirect_page')) {
        $output = t_safe('<p>The user is being redirected to <a href="@destination">@destination</a>.</p>', array('@destination' => $response->getTargetUrl()));

        // Replace RedirectResponse with a fresh Response that includes our content.
        // We pass array() as last param in order to avoid adding regions which would add queries, etc.
        $response = new Response(DefaultHtmlPageRenderer::renderPage($output, 'Devel Redirect', '', array()), 200);
        $event->setResponse($response);
      }
      else {
        $GLOBALS['devel_redirecting'] = TRUE;
      }
    }
  }

  /**
   * Implements EventSubscriberInterface::getSubscribedEvents().
   *
   * @return array
   *   An array of event listener definitions.
   */
  static function getSubscribedEvents() {
    // Set a low value to start as early as possible.
    $events[KernelEvents::REQUEST][] = array('onRequest', -100);

    // Why only large positive value works here?
    $events[KernelEvents::RESPONSE][] = array('onResponse', 1000);

    return $events;
  }

}
