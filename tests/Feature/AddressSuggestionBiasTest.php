<?php

namespace Tests\Feature;

use App\Services\Address\AddressBiasResolver;
use App\Services\Address\IpLocator;
use App\Services\Address\UsStates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AddressSuggestionBiasTest extends TestCase
{
    use RefreshDatabase;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = Str::uuid()->toString();
        config(['services.location_provider' => 'geoapify', 'services.geoapify.api_key' => 'test_key']);
        Cache::flush();

        DB::table('zip_centroids')->insert([
            ['zip' => '90277', 'lat' => 33.849, 'lng' => -118.388],
            ['zip' => '10001', 'lat' => 40.750, 'lng' => -73.997],
        ]);
    }

    private function fakeIp(?array $location): void
    {
        $this->app->instance(IpLocator::class, new class($location) extends IpLocator
        {
            public function __construct(private ?array $location) {}

            public function locate(?string $ip): ?array
            {
                return $this->location;
            }
        });
    }

    private function fakeGeoapify(): void
    {
        Http::fake(['api.geoapify.com/*' => Http::response(['features' => [
            ['properties' => ['place_id' => 'p1', 'formatted' => '179 N Harbor Dr, Redondo Beach, CA', 'address_line1' => '179 N Harbor Dr', 'address_line2' => 'Redondo Beach, CA']],
        ]], 200)]);
    }

    private function autocomplete(string $extra = '')
    {
        return $this->getJson("/api/v1/address/autocomplete?q=179+N+Harbor&session={$this->session}{$extra}");
    }

    private function sentBias(): ?string
    {
        $bias = null;
        Http::assertSent(function ($request) use (&$bias) {
            $bias = $request['bias'] ?? null;

            return true;
        });

        return $bias;
    }

    public function test_zip_is_the_first_hint_used_and_longitude_goes_first(): void
    {
        $this->fakeIp(['lat' => 40.7, 'lng' => -74.0, 'state' => 'NY']);
        $this->fakeGeoapify();

        $this->autocomplete('&postal_code=90277&state=NY')->assertOk()->assertJsonPath('data.0.place_id', 'p1');

        $this->assertSame('proximity:-118.388,33.849', $this->sentBias());
    }

    public function test_zip_plus_four_uses_the_five_digit_zip(): void
    {
        $this->fakeIp(null);
        $this->fakeGeoapify();

        $this->autocomplete('&postal_code=10001-1234')->assertOk();

        $this->assertSame('proximity:-73.997,40.75', $this->sentBias());
    }

    public function test_state_alone_uses_the_state_centre(): void
    {
        $this->fakeIp(null);
        $this->fakeGeoapify();

        $this->autocomplete('&state=ca')->assertOk();

        $this->assertSame('proximity:-119.47,37.18', $this->sentBias());
    }

    public function test_state_does_not_override_an_ip_inside_that_state(): void
    {
        $this->fakeIp(['lat' => 34.05, 'lng' => -118.24, 'state' => 'CA']);
        $this->fakeGeoapify();

        $this->autocomplete('&state=CA')->assertOk();

        $this->assertSame('proximity:-118.24,34.05', $this->sentBias());
    }

    public function test_state_wins_over_an_ip_in_another_state(): void
    {
        $this->fakeIp(['lat' => 40.7, 'lng' => -74.0, 'state' => 'NY']);
        $this->fakeGeoapify();

        $this->autocomplete('&state=CA')->assertOk();

        $this->assertSame('proximity:-119.47,37.18', $this->sentBias());
    }

    public function test_ip_is_used_when_there_are_no_hints(): void
    {
        $this->fakeIp(['lat' => 34.05, 'lng' => -118.24, 'state' => 'CA']);
        $this->fakeGeoapify();

        $this->autocomplete()->assertOk();

        $this->assertSame('proximity:-118.24,34.05', $this->sentBias());
    }

    public function test_without_any_answer_there_is_no_bias_but_the_us_filter_stays(): void
    {
        $this->fakeIp(null);
        $this->fakeGeoapify();

        $this->autocomplete()->assertOk();

        $this->assertNull($this->sentBias());
        Http::assertSent(fn ($request) => $request['filter'] === 'countrycode:us');
    }

    public function test_malformed_or_unknown_hints_are_ignored_not_rejected(): void
    {
        $this->fakeIp(null);
        $this->fakeGeoapify();

        $this->autocomplete('&postal_code=abc&state=ZZ&country=FR')->assertOk();
        $this->assertNull($this->sentBias());

        $this->getJson("/api/v1/address/autocomplete?q=179+N+Harbor&session={$this->session}&postal_code[]=1&state[]=CA")
            ->assertOk();
    }

    public function test_a_zip_missing_from_the_table_falls_back_to_the_state(): void
    {
        $this->fakeIp(null);
        $this->fakeGeoapify();

        $this->autocomplete('&postal_code=99999&state=NY')->assertOk();

        $this->assertSame('proximity:-75.53,42.95', $this->sentBias());
    }

    public function test_identical_requests_are_served_from_the_cache(): void
    {
        $this->fakeIp(['lat' => 34.05, 'lng' => -118.24, 'state' => 'CA']);
        $this->fakeGeoapify();

        $this->autocomplete()->assertOk();
        $this->autocomplete()->assertOk()->assertJsonPath('data.0.place_id', 'p1');

        Http::assertSentCount(1);
    }

    public function test_cache_is_shared_by_neighbours_but_not_by_distant_places(): void
    {
        $this->fakeGeoapify();

        $this->fakeIp(['lat' => 34.02, 'lng' => -118.21, 'state' => 'CA']);
        $this->autocomplete()->assertOk();
        $this->fakeIp(['lat' => 34.04, 'lng' => -118.23, 'state' => 'CA']);
        $this->autocomplete()->assertOk();
        Http::assertSentCount(1);

        $this->fakeIp(['lat' => 40.7, 'lng' => -74.0, 'state' => 'NY']);
        $this->autocomplete()->assertOk();
        Http::assertSentCount(2);
    }

    public function test_every_provider_failure_becomes_an_empty_list(): void
    {
        $this->fakeIp(null);

        foreach ([429, 500, 503] as $status) {
            Cache::flush();
            Http::fake(['api.geoapify.com/*' => Http::response(['error' => 'x'], $status)]);
            $this->autocomplete()->assertOk()->assertJsonPath('success', true)->assertJsonPath('data', []);
        }

        Cache::flush();
        Http::fake(['api.geoapify.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->autocomplete()->assertOk()->assertJsonPath('data', []);

        config(['services.geoapify.api_key' => '']);
        $this->app->forgetInstance(\App\Contracts\LocationServiceInterface::class);
        Cache::flush();
        $this->autocomplete()->assertOk()->assertJsonPath('data', []);
    }

    public function test_a_failure_is_not_cached(): void
    {
        $this->fakeIp(null);
        Http::fake(['api.geoapify.com/*' => Http::sequence()
            ->push(['error' => 'x'], 500)->push(['error' => 'x'], 500)->push(['error' => 'x'], 500)
            ->push(['features' => [['properties' => ['place_id' => 'ok', 'formatted' => 'a', 'address_line1' => 'a', 'address_line2' => 'b']]]], 200),
        ]);

        $this->autocomplete()->assertJsonPath('data', []);
        $this->autocomplete()->assertJsonPath('data.0.place_id', 'ok');
    }

    public function test_the_log_records_the_bias_and_never_the_address_text_or_ip(): void
    {
        $this->fakeIp(['lat' => 34.05, 'lng' => -118.24, 'state' => 'CA']);
        $this->fakeGeoapify();

        $file = tempnam(sys_get_temp_dir(), 'addr').'.log';
        config(['logging.channels.address' => ['driver' => 'single', 'path' => $file, 'level' => 'info']]);
        Log::forgetChannel('address');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->autocomplete()->assertOk();

        $line = file_get_contents($file);
        $this->assertStringContainsString('address.autocomplete', $line);
        $this->assertStringContainsString('"bias":"ip"', $line);
        $this->assertStringContainsString('"results":1', $line);
        $this->assertStringContainsString('"cached":false', $line);
        $this->assertStringNotContainsString('Harbor', $line);
        $this->assertStringNotContainsString('203.0.113.9', $line);
        unlink($file);
    }

    public function test_the_ip_locator_is_quiet_when_the_database_file_is_missing(): void
    {
        config(['services.geo.ip_database' => storage_path('app/geo/does-not-exist.mmdb')]);

        $this->assertNull((new IpLocator)->locate('8.8.8.8'));
        $this->assertNull((new IpLocator)->locate('10.0.0.1'));
        $this->assertNull((new IpLocator)->locate('not-an-ip'));
        $this->assertSame('none', app(AddressBiasResolver::class)->resolve(null, null, '8.8.8.8')['source']);
    }

    public function test_state_helpers(): void
    {
        $this->assertSame('CA', UsStates::code(' ca '));
        $this->assertNull(UsStates::code('ZZ'));
        $this->assertSame('CA', UsStates::codeForName('California'));
        $this->assertSame('DC', UsStates::codeForName('District of Columbia'));
        $this->assertCount(51, array_filter(array_map(fn ($c) => UsStates::center($c), [
            'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
        ])));
    }

    public function test_zip_import_reads_the_census_file_and_replaces_old_rows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'zcta').'.txt';
        file_put_contents($file, "GEOID\tALAND\tAWATER\tALAND_SQMI\tAWATER_SQMI\tINTPTLAT\tINTPTLONG\n"
            ."90210\t1\t1\t1.0\t1.0\t34.100\t-118.410\n"
            ."00601\t1\t1\t1.0\t1.0\t18.180\t-66.750  \n"
            ."bad\t1\t1\t1.0\t1.0\tx\ty\n");

        $this->artisan('geo:import-zip-centroids', ['--file' => $file])->assertSuccessful();

        $this->assertSame(2, DB::table('zip_centroids')->count());
        $this->assertSame('-66.750000', DB::table('zip_centroids')->where('zip', '00601')->value('lng'));
        $this->assertFalse(DB::table('zip_centroids')->where('zip', '90277')->exists());

        // A file with no usable rows must not wipe the table.
        file_put_contents($file, "nothing useful\n");
        $this->artisan('geo:import-zip-centroids', ['--file' => $file])->assertFailed();
        $this->assertSame(2, DB::table('zip_centroids')->count());
        unlink($file);
    }

    public function test_ip_refresh_keeps_the_old_file_when_the_download_fails(): void
    {
        $dir = sys_get_temp_dir().'/geo-'.uniqid();
        mkdir($dir);
        $target = $dir.'/dbip.mmdb';
        file_put_contents($target, 'old');
        config(['services.geo.ip_database' => $target]);
        Http::fake(['download.db-ip.com/*' => Http::response('', 404)]);

        $this->artisan('geo:refresh-ip-database')->assertFailed();

        $this->assertSame('old', file_get_contents($target));
        $this->assertSame(['dbip.mmdb'], array_values(array_diff(scandir($dir), ['.', '..'])));
    }
}
