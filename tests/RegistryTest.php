<?php

declare(strict_types=1);

namespace AegisFlow\Tests;

use AegisFlow\Devices\Registry;
use AegisFlow\Storage\JsonRepo;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private JsonRepo $repo;
    private Registry $registry;

    protected function setUp(): void
    {
        $this->repo = new JsonRepo();

        // Clear storage for test isolation
        $this->repo->write('devices', []);
        $this->repo->write('commands', []);
        $this->repo->write('logs', []);

        $this->registry = new Registry($this->repo);
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        $this->repo->write('devices', []);
    }

    public function testDeviceRegistrationReturnsIdAndStoresDevice(): void
    {
        $info = [
            'platform' => 'android',
            'name' => 'Pixel Test Device',
            'tags' => ['test', 'mobile'],
            'os_version' => '14',
        ];

        $deviceId = $this->registry->add($info);

        $this->assertNotEmpty($deviceId, 'Registration must return a non-empty ID');
        $this->assertStringStartsWith('dev_', $deviceId, 'ID should be prefixed');

        $retrieved = $this->registry->find($deviceId);
        $this->assertIsArray($retrieved);
        $this->assertEquals($deviceId, $retrieved['id']);
        $this->assertEquals('android', $retrieved['platform']);
        $this->assertEquals('Pixel Test Device', $retrieved['name']);
        $this->assertEquals(['test', 'mobile'], $retrieved['tags']);
        $this->assertEquals('14', $retrieved['os_version']);
        $this->assertArrayHasKey('last_seen', $retrieved);
        $this->assertEquals('offline', $retrieved['status'] ?? null);
    }

    public function testUpdateStatusModifiesDeviceRecord(): void
    {
        $deviceId = $this->registry->add([
            'platform' => 'ios',
            'name' => 'iPhone Simulator',
        ]);

        $this->registry->updateStatus($deviceId, [
            'status' => 'online',
            'battery' => 92,
            'last_seen' => 1735689600,
            'ip' => '192.168.1.100',
        ]);

        $device = $this->registry->find($deviceId);

        $this->assertEquals('online', $device['status']);
        $this->assertEquals(92, $device['battery']);
        $this->assertEquals(1735689600, $device['last_seen']);
        $this->assertEquals('192.168.1.100', $device['ip']);
    }

    public function testByTagReturnsOnlyMatchingDevices(): void
    {
        $this->registry->add([
            'platform' => 'android',
            'name' => 'Android Phone',
            'tags' => ['test', 'lab'],
        ]);

        $this->registry->add([
            'platform' => 'windows',
            'name' => 'Work Laptop',
            'tags' => ['lab', 'production'],
        ]);

        $this->registry->add([
            'platform' => 'ios',
            'name' => 'Test iPad',
            'tags' => ['test'],
        ]);

        $labDevices = $this->registry->byTag('lab');
        $this->assertCount(2, $labDevices);
        $this->assertEquals('android', $labDevices[0]['platform']);
        $this->assertEquals('windows', $labDevices[1]['platform']);

        $productionDevices = $this->registry->byTag('production');
        $this->assertCount(1, $productionDevices);
        $this->assertEquals('Work Laptop', $productionDevices[0]['name']);

        // Case-insensitive matching
        $this->assertCount(3, $this->registry->byTag('TEST'));
    }

    public function testAllReturnsCompleteDeviceList(): void
    {
        $this->registry->add(['platform' => 'android', 'name' => 'Device A']);
        $this->registry->add(['platform' => 'ios', 'name' => 'Device B']);
        $this->registry->add(['platform' => 'windows', 'name' => 'Device C']);

        $all = $this->registry->all();

        $this->assertCount(3, $all);
        $names = array_column($all, 'name');
        $this->assertContains('Device A', $names);
        $this->assertContains('Device B', $names);
        $this->assertContains('Device C', $names);
    }

    public function testCommandRoundTripSimulation(): void
    {
        // Simulate full lifecycle: register → command dispatch simulation
        // → response received → status updated
        $deviceId = $this->registry->add([
            'platform' => 'windows',
            'name' => 'Command Test PC',
            'tags' => ['test'],
        ]);

        // Simulate command being sent (status change)
        $this->registry->updateStatus($deviceId, [
            'status' => 'online',
            'last_command' => 'screenshot',
            'last_command_at' => time(),
        ]);

        $device = $this->registry->find($deviceId);
        $this->assertEquals('screenshot', $device['last_command'] ?? null);
        $this->assertArrayHasKey('last_command_at', $device);

        // Simulate response received from agent
        $this->registry->updateStatus($deviceId, [
            'status' => 'online',
            'battery' => 76,
            'last_response' => 'success',
            'last_seen' => time(),
        ]);

        $updated = $this->registry->find($deviceId);
        $this->assertEquals(76, $updated['battery']);
        $this->assertEquals('success', $updated['last_response'] ?? null);

        // Verify it still appears in tag filter after updates
        $testDevices = $this->registry->byTag('test');
        $this->assertCount(1, $testDevices);
        $this->assertEquals($deviceId, $testDevices[0]['id']);
    }

    public function testFindReturnsEmptyArrayForUnknownDevice(): void
    {
        $result = $this->registry->find('non-existent-id');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testRegistrationWithNoTagsNormalizesToEmptyArray(): void
    {
        $id = $this->registry->add([
            'platform' => 'android',
            'name' => 'No Tags Device',
        ]);

        $device = $this->registry->find($id);
        $this->assertIsArray($device['tags'] ?? null);
        $this->assertEmpty($device['tags']);
    }
}
