<?php

declare(strict_types=1);

namespace AegisFlow\Tests;

use AegisFlow\Devices\Registry;
use AegisFlow\Devices\Protocol;
use AegisFlow\Storage\JsonRepo;
use AegisFlow\Commands\Dispatcher;
use AegisFlow\Core\Config;
use AegisFlow\Logger\AuditLog;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Registry with added coverage for macOS platform and
 * AppleScript command type (via Protocol and Dispatcher).
 */
final class RegistryTest extends TestCase
{
    private JsonRepo $repo;
    private Registry $registry;
    private Protocol $protocol;

    protected function setUp(): void
    {
        $this->repo = new JsonRepo();
        // Reset storage for test isolation
        $this->repo->write('devices', []);
        $this->repo->write('commands', []);
        $this->registry = new Registry($this->repo);
        $this->protocol = new Protocol();
    }

    public function testMacosDeviceRegistration(): void
    {
        $info = [
            'platform' => 'macos',
            'name' => 'Test MacBook Pro',
            'tags' => ['lab', 'apple'],
            'os_version' => '14.5',
        ];

        $deviceId = $this->registry->add($info);
        $this->assertNotEmpty($deviceId, 'Device ID should be generated for macOS');

        $device = $this->registry->find($deviceId);
        $this->assertEquals('macos', $device['platform']);
        $this->assertEquals('Test MacBook Pro', $device['name']);
        $this->assertContains('lab', $device['tags'] ?? []);
    }

    public function testProtocolSupportsAppleScript(): void
    {
        $command = $this->protocol->createCommand('applescript', [
            'script' => 'tell application "Finder" to display dialog "Hello from Aegis Flow"',
            'timeout' => 15,
        ]);

        $this->assertArrayHasKey('id', $command);
        $this->assertEquals('applescript', $command['type']);
        $this->assertArrayHasKey('script', $command['params']);
        $this->assertEquals('1.1', $command['protocol_version']);
    }

    public function testDispatcherWithMacosAndAppleScript(): void
    {
        $config = new Config();
        $auditLog = new AuditLog($this->repo);

        $deviceId = $this->registry->add([
            'platform' => 'macos',
            'name' => 'Aegis Test Mac',
            'tags' => ['test'],
        ]);

        $dispatcher = new Dispatcher($this->registry, $config, $auditLog);
        $success = $dispatcher->dispatch($deviceId, 'applescript', [
            'script' => 'display notification "Test completed" with title "Aegis Flow"',
        ]);

        $this->assertTrue($success);

        $commands = $this->repo->read('commands');
        $this->assertNotEmpty($commands);

        $last = end($commands);
        $this->assertEquals($deviceId, $last['device_id']);
        $this->assertEquals('macos', $last['platform']);
        $this->assertEquals('applescript', $last['type']);
        $this->assertEquals('pending', $last['status']);
    }

    public function testByTagFiltersMacosDevices(): void
    {
        $this->registry->add(['platform' => 'android', 'tags' => ['test']]);
        $this->registry->add(['platform' => 'macos', 'tags' => ['test', 'apple']]);
        $this->registry->add(['platform' => 'ios', 'tags' => ['other']]);

        $tagged = $this->registry->byTag('test');
        $this->assertCount(2, $tagged);
        $platforms = array_column($tagged, 'platform');
        $this->assertContains('macos', $platforms);
    }

    public function testUpdateStatusAndAll(): void
    {
        $id = $this->registry->add(['platform' => 'macos', 'name' => 'StatusTest']);
        $this->registry->updateStatus($id, [
            'status' => 'online',
            'battery' => 92,
            'last_seen' => time(),
        ]);

        $all = $this->registry->all();
        $this->assertCount(1, $all);
        $this->assertEquals('online', $all[0]['status']);
        $this->assertEquals(92, $all[0]['battery']);
    }
}
