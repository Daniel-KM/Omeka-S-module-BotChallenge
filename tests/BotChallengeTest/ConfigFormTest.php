<?php declare(strict_types=1);

namespace BotChallengeTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests for the config form of the module, saved by the module Common.
 */
class ConfigFormTest extends AbstractHttpControllerTestCase
{
    protected array $managedSettings = [
        'botchallenge_salt',
        'botchallenge_delay',
        'botchallenge_cookie_lifetime',
        'botchallenge_bind_ip',
        'botchallenge_test_headless',
        'botchallenge_exception_paths',
        'botchallenge_exception_bots',
        'botchallenge_exception_ips',
        'botchallenge_exception_ips_all',
    ];

    protected array $savedSettings = [];

    public function setUp(): void
    {
        parent::setUp();
        foreach ($this->managedSettings as $key) {
            $this->savedSettings[$key] = $this->settings()->get($key);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $value) {
            $this->settings()->set($key, $value);
        }
        parent::tearDown();
    }

    protected function settings()
    {
        return $this->getApplication()->getServiceManager()->get('Omeka\Settings');
    }

    /**
     * Post the config form of the module with valid tokens.
     */
    protected function postConfig(array $data): void
    {
        // Each dispatch resets the application and its session, so the tokens
        // are created after the reset, then the request is run directly.
        $this->reset();
        $this->getApplication();
        $this->loginAsAdmin();
        $data['module_BotChallenge_configure_csrf'] = (new \Laminas\Form\Element\Csrf('module_BotChallenge_configure_csrf'))->getValue();
        $data['csrf'] = (new \Laminas\Form\Element\Csrf('csrf'))->getValue();
        $this->url('/admin/module/configure?id=BotChallenge', 'POST', $data);
        $this->getApplication()->run();
    }

    protected function defaultPost(): array
    {
        return [
            'botchallenge_salt' => 'salt',
            'botchallenge_delay' => '5',
            'botchallenge_cookie_lifetime' => '7',
            'botchallenge_bind_ip' => '0',
            'botchallenge_test_headless' => '1',
            'botchallenge_exception_paths' => "/api\n/api-local",
            'botchallenge_exception_ips' => '',
        ];
    }

    public function testConfigFormRenders(): void
    {
        $this->dispatch('/admin/module/configure?id=BotChallenge');
        $this->assertResponseStatusCode(200);
        $this->assertQuery('input[name="botchallenge_delay"]');
    }

    public function testSaveOptions(): void
    {
        $data = $this->defaultPost();
        $data['botchallenge_delay'] = '8';
        $data['botchallenge_bind_ip'] = '1';
        $data['botchallenge_exception_bots'] = ['googlebot', 'unknown-bot'];
        $data['botchallenge_exception_ips'] = "192.0.2.0/24\n";
        $this->postConfig($data);

        $this->assertResponseStatusCode(302);
        $settings = $this->settings();
        $this->assertSame(8, $settings->get('botchallenge_delay'));
        $this->assertSame('1', (string) $settings->get('botchallenge_bind_ip'));
        $this->assertSame(['googlebot'], $settings->get('botchallenge_exception_bots'));
        $this->assertSame(['192.0.2.0/24'], array_values($settings->get('botchallenge_exception_ips')));
        $this->assertContains('192.0.2.0/24', $settings->get('botchallenge_exception_ips_all'));
    }

    public function testEmptySaltIsRegenerated(): void
    {
        $data = $this->defaultPost();
        $data['botchallenge_salt'] = '  ';
        $this->postConfig($data);

        $this->assertResponseStatusCode(302);
        $this->assertSame(64, strlen((string) $this->settings()->get('botchallenge_salt')));
    }
}
