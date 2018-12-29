<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */


namespace craftunit\web;


use Codeception\Test\Unit;
use craft\helpers\UrlHelper;
use craft\test\mockclasses\controllers\TestController;
use craft\web\Response;
use craft\web\View;
use yii\base\Action;
use yii\base\ExitException;
use yiiunit\TestCase;

/**
 * Unit tests for ControllerTest
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @author Global Network Group | Giel Tettelaar <giel@yellowflash.net>
 * @since 3.0
 */
class ControllerTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    /**
     * @var TestController
     */
    private $controller;
    public function _before()
    {
        parent::_before();
        $_SERVER['REQUEST_URI'] = 'https://craftcms.com/admin/dashboard';
        $this->controller = new TestController('test', \Craft::$app);
    }
    public function testBeforeAction()
    {
        $this->tester->expectThrowable(ExitException::class, function () {
            // AllowAnonymous should redirect and Craft::$app->exit(); I.E. An exit exception
            $this->controller->beforeAction(new Action('not-allow-anonymous', $this->controller));
        });

        $this->assertTrue($this->controller->beforeAction(new Action('allow-anonymous', $this->controller)));
    }

    public function testRunActionJsonError()
    {
        // We accept JSON.
        \Craft::$app->getRequest()->setAcceptableContentTypes(['application/json' => true]);
        \Craft::$app->getRequest()->headers->set('Accept', 'application/json');

        /* @var Response $resp */
        $resp = $this->controller->runAction('me-dont-exist');

        // As long as this is set. We can expect yii to do its thing.
        $this->assertSame(Response::FORMAT_JSON, $resp->format);
    }

    public function testTemplateRendering()
    {
        // We need to render a template from the site dir.
        \Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_SITE);

        $response = $this->controller->renderTemplate('template');

        // Again. If this is all good. We can expect Yii to do its thing.
        $this->assertSame('Im a template!', $response->data);
        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaders()->get('content-type'));
    }

    /**
     * If the content-type headers are already set. Render Template should ignore attempting to set them.
     * @throws \yii\base\Exception
     */
    public function testTemplateRenderingIfHeadersAlreadySet()
    {
        // We need to render a template from the site dir.
        \Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_SITE);
        \Craft::$app->getResponse()->getHeaders()->set('content-type', 'HEADERS');

        $response = $this->controller->renderTemplate('template');

        // Again. If this is all good. We can expect Yii to do its thing.
        $this->assertSame('Im a template!', $response->data);
        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertSame('HEADERS', $response->getHeaders()->get('content-type'));
    }

    public function testRedirectToPostedUrl()
    {
        $baseUrl = $this->getBaseUrlForRedirect();
        $redirect = \Craft::$app->getSecurity()->hashData('craft/do/stuff');

        // Default
        $default = $this->controller->redirectToPostedUrl();

        // Test that with nothing passed in. It defaults to the base. See self::getBaseUrlForRedirect() for more info.
        $this->assertSame(
            $baseUrl,
            $default->headers->get('Location')
        );

        // What happens when we pass in a param. 
        \Craft::$app->getRequest()->setBodyParams(['redirect' => $redirect]);
        $default = $this->controller->redirectToPostedUrl();
        $this->assertSame($baseUrl.'?p=craft/do/stuff', $default->headers->get('Location'));
    }

    public function testRedirectToPostedWithSetDefault()
    {
        $baseUrl = $this->getBaseUrlForRedirect();
        $withDefault = $this->controller->redirectToPostedUrl(null, 'craft/do/stuff');
        $this->assertSame($baseUrl.'?p=craft/do/stuff', $withDefault->headers->get('Location'));

    }

    public function testAsJsonP()
    {
        $result = $this->controller->asJsonP(['test' => 'test']);
        $this->assertSame(Response::FORMAT_JSONP, $result->format);
        $this->assertSame(['test' => 'test'], $result->data);
    }
    public function testAsRaw()
    {
        $result = $this->controller->asRaw(['test' => 'test']);
        $this->assertSame(Response::FORMAT_RAW, $result->format);
        $this->assertSame(['test' => 'test'], $result->data);
    }
    public function testAsErrorJson()
    {
        $result = $this->controller->asErrorJson('im an error');
        $this->assertSame(Response::FORMAT_JSON, $result->format);
        $this->assertSame(['error' => 'im an error'], $result->data);
    }

    public function testRedirect()
    {
        $this->assertSame(
            $this->getBaseUrlForRedirect().'?p=do/stuff',
            $this->controller->redirect('do/stuff')->headers->get('Location')
        );

        // Based on the entryScript param in the craft module. If nothing is passed in the Craft::$app->getHomeUrl(); will be called
        // Which will redirect to the $_SERVER['SCRIPT_NAME'] param.
        $this->assertSame(
            'index.php',
            $this->controller->redirect(null)->headers->get('Location')
        );
    }

    // Helpers
    // =========================================================================

    private function determineUrlScheme()
    {
        return !\Craft::$app->getRequest()->getIsConsoleRequest() && \Craft::$app->getRequest()->getIsSecureConnection() ? 'https' : 'http';
    }
    private function getBaseUrlForRedirect()
    {
        $scheme = $this->determineUrlScheme();
        return UrlHelper::urlWithScheme(\Craft::$app->getConfig()->getGeneral()->siteUrl.'index.php', $scheme);
    }
    private function setMockUser()
    {
        \Craft::$app->getUser()->setIdentity(
            \Craft::$app->getUsers()->getUserById('1')
        );
    }
}