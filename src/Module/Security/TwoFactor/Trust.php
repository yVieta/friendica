<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Module\Security\TwoFactor;

use Friendica\App;
use Friendica\BaseModule;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Core\Session\Capability\IHandleSessions;
use Friendica\Model\User;
use Friendica\Model\User\Cookie;
use Friendica\Module\Response;
use Friendica\Network\HTTPException\NotFoundException;
use Friendica\Security\Authentication;
use Friendica\Util\Profiler;
use Friendica\Security\TwoFactor;
use Psr\Log\LoggerInterface;

/**
 * Page 2: Trust Browser dialog
 *
 * @package Friendica\Module\TwoFactor
 */
class Trust extends BaseModule
{
	/** @var App  */
	protected $app;
	/** @var Authentication  */
	protected $auth;
	/** @var IHandleSessions  */
	protected $session;
	/** @var Cookie  */
	protected $cookie;
	/** @var TwoFactor\Factory\TrustedBrowser  */
	protected $trustedBrowserFactory;
	/** @var TwoFactor\Repository\TrustedBrowser  */
	protected $trustedBrowserRepositoy;

	public function __construct(App $app, Authentication $auth, L10n $l10n, App\BaseURL $baseUrl, App\Arguments $args, LoggerInterface $logger, Profiler $profiler, IHandleSessions $session, Cookie $cookie, TwoFactor\Factory\TrustedBrowser $trustedBrowserFactory, TwoFactor\Repository\TrustedBrowser $trustedBrowserRepositoy, Response $response, array $server, array $parameters = [])
	{
		parent::__construct($l10n, $baseUrl, $args, $logger, $profiler, $response, $server, $parameters);

		$this->app                     = $app;
		$this->auth                    = $auth;
		$this->session                 = $session;
		$this->cookie                  = $cookie;
		$this->trustedBrowserFactory   = $trustedBrowserFactory;
		$this->trustedBrowserRepositoy = $trustedBrowserRepositoy;
	}

	protected function post(array $request = [])
	{
		if (!local_user() || !$this->session->get('2fa')) {
			return;
		}

		$action = $request['action'] ?? '';

		if (!empty($action)) {
			self::checkFormSecurityTokenRedirectOnError('2fa', 'twofactor_trust');

			switch ($action) {
				case 'trust':
				case 'dont_trust':
					$trustedBrowser = $this->trustedBrowserFactory->createForUserWithUserAgent(local_user(), $this->server['HTTP_USER_AGENT'], $action === 'trust');
					$this->trustedBrowserRepositoy->save($trustedBrowser);

					// The string is sent to the browser to be sent back with each request
					if (!$this->cookie->set('2fa_cookie_hash', $trustedBrowser->cookie_hash)) {
						notice($this->t('Couldn\'t save browser to Cookie.'));
					};
					break;
			}

			$this->auth->setForUser($this->app, User::getById($this->app->getLoggedInUserId()), true, true);
		}
	}

	protected function content(array $request = []): string
	{
		if (!local_user() || !$this->session->get('2fa')) {
			$this->baseUrl->redirect();
		}

		if ($this->cookie->get('2fa_cookie_hash')) {
			try {
				$trustedBrowser = $this->trustedBrowserRepositoy->selectOneByHash($this->cookie->get('2fa_cookie_hash'));
				if (!$trustedBrowser->trusted) {
					$this->auth->setForUser($this->app, User::getById($this->app->getLoggedInUserId()), true, true);
					$this->baseUrl->redirect();
				}
			} catch (NotFoundException $exception) {
				$this->logger->notice('Trusted Browser of the cookie not found.', ['cookie_hash' => $this->cookie->get('trusted'), 'uid' => $this->app->getLoggedInUserId(), 'exception' => $exception]);
			}
		}

		return Renderer::replaceMacros(Renderer::getMarkupTemplate('twofactor/trust.tpl'), [
			'$form_security_token' => self::getFormSecurityToken('twofactor_trust'),

			'$title'            => $this->t('Trust this browser?'),
			'$message'          => $this->t('<p>If you choose to trust this browser, you will not be asked for a verification code the next time you sign in.</p>'),
			'$not_now_label'    => $this->t('Not now'),
			'$dont_trust_label' => $this->t('Don\'t trust'),
			'$trust_label'      => $this->t('Trust'),
		]);
	}
}
