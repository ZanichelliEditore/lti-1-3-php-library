<?php

namespace Certification;

use Carbon\Carbon;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Packback\Lti1p3\Interfaces\ICache;
use Packback\Lti1p3\Interfaces\ICookie;
use Packback\Lti1p3\Interfaces\IDatabase;
use Packback\Lti1p3\Interfaces\ILtiServiceConnector;
use Packback\Lti1p3\JwksEndpoint;
use Packback\Lti1p3\LtiConstants;
use Packback\Lti1p3\LtiDeployment;
use Packback\Lti1p3\LtiException;
use Packback\Lti1p3\LtiMessageLaunch;
use Packback\Lti1p3\LtiOidcLogin;
use Packback\Lti1p3\LtiRegistration;
use Tests\TestCase;

class TestCache implements ICache
{
    private $launchData = [];
    private $nonce;

    public function getLaunchData(string $key): ?array
    {
        return $this->launchData[$key] ?? null;
    }

    public function cacheLaunchData(string $key, array $jwtBody): void
    {
        $this->launchData[$key] = $jwtBody;
    }

    public function cacheNonce(string $nonce, string $state): void
    {
        $this->nonce = $state;
    }

    public function checkNonceIsValid(string $nonce, string $state): bool
    {
        return $this->nonce === $state;
    }

    public function cacheAccessToken(string $key, string $accessToken): void
    {
        $this->launchData[$key] = $accessToken;
    }

    public function getAccessToken(string $key): ?string
    {
        return $this->launchData[$key] ?? null;
    }

    public function clearAccessToken(string $key): void
    {
        $this->launchData[$key] = null;
    }
}

class TestCookie implements ICookie
{
    private $cookies = [];

    public function getCookie(string $name): ?string
    {
        return $this->cookies[$name];
    }

    public function setCookie(string $name, string $value, $exp = 3600, $options = []): void
    {
        $this->cookies[$name] = $value;
    }
}

class TestDb implements IDatabase
{
    private $registrations = [];
    private $deplomyments = [];

    public function __construct($registration, $deployment)
    {
        $this->registrations[$registration->getIssuer()] = $registration;
        $this->deployments[$deployment->getDeploymentId()] = $deployment;
    }

    public function findRegistrationByIssuer($iss, $client_id = null)
    {
        return $this->registrations[$iss];
    }

    public function findDeployment($iss, $deployment_id, $client_id = null)
    {
        return $this->deployments[$iss];
    }
}

class Lti13CertificationTest extends TestCase
{
    public const ISSUER_URL = 'https://ltiadvantagevalidator.imsglobal.org';
    public const JWKS_FILE = '/tmp/jwks.json';
    public const CERT_DATA_DIR = __DIR__.'/../data/certification/';
    public const PRIVATE_KEY = __DIR__.'/../data/private.key';

    public const STATE = 'state';

    private $issuer;
    private $key;

    public function setUp(): void
    {
        $this->issuer = [
            'id' => 'issuer_id',
            'issuer' => static::ISSUER_URL,
            'client_id' => 'imstester_3dfad6d',
            'auth_login_url' => 'https://ltiadvantagevalidator.imsglobal.org/ltitool/oidcauthurl.html',
            'auth_token_url' => 'https://ltiadvantagevalidator.imsglobal.org/ltitool/authcodejwt.html',
            'alg' => 'RS256',
            'key_set_url' => static::JWKS_FILE,
            'kid' => 'key-id',
            'tool_private_key' => file_get_contents(static::PRIVATE_KEY),
        ];

        $this->key = [
            'version' => LtiConstants::V1_3,
            'issuer_id' => $this->issuer['id'],
            'deployment_id' => 'testdeploy',
            'campus_id' => 1,
        ];

        $this->payload = [
            LtiConstants::MESSAGE_TYPE => 'LtiResourceLinkRequest',
            LtiConstants::VERSION => LtiConstants::V1_3,
            LtiConstants::RESOURCE_LINK => [
                'id' => 'd3a2504bba5184799a38f141e8df2335cfa8206d',
                'description' => null,
                'title' => null,
                'validation_context' => null,
                'errors' => [
                    'errors' => [],
                ],
            ],
            'aud' => $this->issuer['client_id'],
            'azp' => $this->issuer['client_id'],
            LtiConstants::DEPLOYMENT_ID => $this->key['deployment_id'],
            'exp' => Carbon::now()->addDay()->timestamp,
            'iat' => Carbon::now()->subDay()->timestamp,
            'iss' => $this->issuer['issuer'],
            'nonce' => 'nonce-5e73ef2f4c6ea0.93530902',
            'sub' => '66b6a854-9f43-4bb2-90e8-6653c9126272',
            LtiConstants::TARGET_LINK_URI => 'https://lms-api.packback.localhost/api/lti/launch',
            LtiConstants::CONTEXT => [
                'id' => 'd3a2504bba5184799a38f141e8df2335cfa8206d',
                'label' => 'Canvas Unlauched',
                'title' => 'Canvas - A Fresh Course That Remains Unlaunched',
                'type' => [
                    LtiConstants::COURSE_OFFERING,
                ],
                'validation_context' => null,
                'errors' => [
                    'errors' => [],
                ],
            ],
            LtiConstants::TOOL_PLATFORM => [
                'guid' => 'FnwyPrXqSxwv8QCm11UwILpDJMAUPJ9WGn8zcvBM:canvas-lms',
                'name' => 'Packback Engineering',
                'version' => 'cloud',
                'product_family_code' => 'canvas',
                'validation_context' => null,
                'errors' => [
                    'errors' => [],
                ],
            ],
            LtiConstants::LAUNCH_PRESENTATION => [
                'document_target' => 'iframe',
                'height' => 400,
                'width' => 800,
                'return_url' => 'https://canvas.localhost/courses/3/external_content/success/external_tool_redirect',
                'locale' => 'en',
                'validation_context' => null,
                'errors' => [
                    'errors' => [],
                ],
            ],
            'locale' => 'en',
            LtiConstants::ROLES => [
                LtiConstants::INSTITUTION_ADMINISTRATOR,
                LtiConstants::INSTITUTION_INSTRUCTOR,
                LtiConstants::MEMBERSHIP_INSTRUCTOR,
                LtiConstants::SYSTEM_SYSADMIN,
                LtiConstants::SYSTEM_USER,
            ],
            LtiConstants::CUSTOM => [],
            'errors' => [
                'errors' => [],
            ],
        ];

        $this->db = new TestDb(
            new LtiRegistration([
                'issuer' => static::ISSUER_URL,
                'clientId' => $this->issuer['client_id'],
                'keySetUrl' => static::JWKS_FILE,
            ]),
            (new LtiDeployment())->setDeploymentId(static::ISSUER_URL)
        );
        $this->cache = new TestCache();
        $this->cookie = new TestCookie();
        $this->cookie->setCookie(
            LtiOidcLogin::COOKIE_PREFIX.static::STATE,
            static::STATE
        );
        $this->serviceConnector = Mockery::mock(ILtiServiceConnector::class);
    }

    public function buildJWT($data, $header)
    {
        $jwks = json_encode(JwksEndpoint::new([
            $this->issuer['kid'] => $this->issuer['tool_private_key'],
        ])->getPublicJwks());
        file_put_contents(static::JWKS_FILE, $jwks);

        // If we pass in a header, use that instead of creating one automatically based on params given
        if ($header) {
            $segments = [];
            $segments[] = JWT::urlsafeB64Encode(JWT::jsonEncode($header));
            $segments[] = JWT::urlsafeB64Encode(JWT::jsonEncode($data));
            $signing_input = \implode('.', $segments);

            $signature = JWT::sign($signing_input, $this->issuer['tool_private_key'], $this->issuer['alg']);
            $segments[] = JWT::urlsafeB64Encode($signature);

            return \implode('.', $segments);
        }

        return JWT::encode($data, $this->issuer['tool_private_key'], $alg, $this->issuer['kid']);
    }

    // tests
    public function testLtiVersionPassedIsNot13()
    {
        $payload = $this->payload;
        $payload[LtiConstants::VERSION] = 'not-1.3';

        $this->expectExceptionMessage('Incorrect version, expected 1.3.0');

        $this->launch($payload);
    }

    public function testNoLtiVersionPassedIsInJwt()
    {
        $payload = $this->payload;
        unset($payload[LtiConstants::VERSION]);

        $this->expectExceptionMessage('Missing LTI Version');

        $this->launch($payload);
    }

    public function testJwtPassedIsNotLti13Jwt()
    {
        $jwt = $this->buildJWT([], $this->issuer);
        $jwt_r = explode('.', $jwt);
        array_pop($jwt_r);
        $jwt = implode('.', $jwt_r);

        $params = [
            'utf8' => '✓',
            'id_token' => $jwt,
            'state' => static::STATE,
        ];

        $this->expectExceptionMessage('Invalid id_token, JWT must contain 3 parts');

        LtiMessageLaunch::new($this->db, $this->cache, $this->cookie)
            ->validate($params);
    }

    public function testExpAndIatFieldsInvalid()
    {
        $payload = $this->payload;
        $payload['exp'] = Carbon::now()->subYear()->timestamp;
        $payload['iat'] = Carbon::now()->subYear()->timestamp;

        $this->expectExceptionMessage('Invalid signature on id_token');

        $this->launch($payload);
    }

    public function testMessageTypeClaimMissing()
    {
        $payload = $this->payload;
        unset($payload[LtiConstants::MESSAGE_TYPE]);

        $this->expectExceptionMessage('Invalid message type');

        $this->launch($payload);
    }

    public function testRoleClaimMissing()
    {
        $payload = $this->payload;
        unset($payload[LtiConstants::ROLES]);

        $this->expectExceptionMessage('Missing Roles Claim');

        $this->launch($payload);
    }

    public function testDeploymentIdClaimMissing()
    {
        $payload = $this->payload;
        unset($payload[LtiConstants::DEPLOYMENT_ID]);

        $this->expectExceptionMessage('No deployment ID was specified');

        $this->launch($payload);
    }

    public function testLaunchWithMissingResourceLinkId()
    {
        $payload = $this->payload;
        unset($payload['sub']);

        $this->expectExceptionMessage('Must have a user (sub)');

        $this->launch($payload);
    }

    public function testInvalidCertificationCases()
    {
        $testCasesDir = static::CERT_DATA_DIR.'invalid';

        $testCases = scandir($testCasesDir);
        // Remove . and ..
        array_shift($testCases);
        array_shift($testCases);

        $casesCount = count($testCases);
        $testedCases = 0;

        $request = Mockery::mock(Response::class);
        $this->serviceConnector->shouldReceive('makeRequest')
            // All but one invalid cert case get the JWK
            ->times($casesCount - 1)
            ->andReturn($request);
        $this->serviceConnector->shouldReceive('getResponseBody')
            ->times($casesCount - 1)
            ->andReturn(json_decode(file_get_contents(static::JWKS_FILE), true));

        foreach ($testCases as $testCase) {
            $testCaseDir = $testCasesDir.DIRECTORY_SEPARATOR.$testCase.DIRECTORY_SEPARATOR;

            $jwtHeader = null;
            if (file_exists($testCaseDir.'header.json')) {
                $jwtHeader = json_decode(
                    file_get_contents($testCaseDir.'header.json'),
                    true
                );
            }

            $payload = json_decode(
                file_get_contents($testCaseDir.'payload.json'),
                true
            );

            $keep = null;
            if (file_exists($testCaseDir.'keep.json')) {
                $keep = json_decode(
                    file_get_contents($testCaseDir.'keep.json'),
                    true
                );
            }

            if (!$keep || !in_array('exp', $keep, true)) {
                $payload['exp'] = Carbon::now()->addDay()->timestamp;
            }
            if (!$keep || !in_array('iat', $keep, true)) {
                $payload['iat'] = Carbon::now()->subDay()->timestamp;
            }

            // I couldn't find a better output function
            echo PHP_EOL."--> TESTING INVALID TEST CASE: {$testCase}";

            $jwt = $this->buildJWT($payload, $this->issuer, $jwtHeader);
            if (isset($payload['nonce'])) {
                $this->cache->cacheNonce($payload['nonce'], static::STATE);
            }

            $params = [
                'utf8' => '✓',
                'id_token' => $jwt,
                'state' => static::STATE,
            ];

            try {
                LtiMessageLaunch::new($this->db, $this->cache, $this->cookie, $this->serviceConnector)
                    ->validate($params);
            } catch (\Exception $e) {
                $this->assertInstanceOf(LtiException::class, $e);
            }

            ++$testedCases;
        }
        echo PHP_EOL;
        $this->assertEquals($casesCount, $testedCases);
    }

    public function testValidCertificationCases()
    {
        $testCasesDir = static::CERT_DATA_DIR.'valid';

        $testCases = scandir($testCasesDir);
        // Remove . and ..
        array_shift($testCases);
        array_shift($testCases);

        $casesCount = count($testCases);
        $testedCases = 0;

        foreach ($testCases as $testCase) {
            $payload = json_decode(
                file_get_contents($testCasesDir.DIRECTORY_SEPARATOR.$testCase.DIRECTORY_SEPARATOR.'payload.json'),
                true
            );

            $payload['exp'] = Carbon::now()->addDay()->timestamp;
            $payload['iat'] = Carbon::now()->subDay()->timestamp;
            // Set a random context ID to avoid reusing the same LMS Course
            $payload[LtiConstants::CONTEXT]['id'] = 'lms-course-id';
            // Set a random user ID to avoid reusing the same LmsUser
            $payload['sub'] = 'lms-user-id';

            // I couldn't find a better output function
            echo PHP_EOL."--> TESTING VALID TEST CASE: {$testCase}";

            $jwt = $this->buildJWT($payload, $this->issuer);
            $this->cache->cacheNonce($payload['nonce'], static::STATE);

            $params = [
                'utf8' => '✓',
                'id_token' => $jwt,
                'state' => static::STATE,
            ];

            $request = Mockery::mock(Response::class);
            $this->serviceConnector->shouldReceive('makeRequest')
                ->once()->andReturn($request);
            $this->serviceConnector->shouldReceive('getResponseBody')
                ->once()->andReturn(json_decode(file_get_contents(static::JWKS_FILE), true));

            $result = LtiMessageLaunch::new($this->db, $this->cache, $this->cookie, $this->serviceConnector)
                ->validate($params);

            // Assertions
            $this->assertInstanceOf(LtiMessageLaunch::class, $result);

            ++$testedCases;
        }
        echo PHP_EOL;
        $this->assertEquals($casesCount, $testedCases);
    }

    private function login($loginData = null)
    {
        $loginData = $loginData ?? [
            'iss' => $this->issuer['issuer'],
            'login_hint' => '535fa085f22b4655f48cd5a36a9215f64c062838',
        ];
        $loginData['client_id'] = $this->issuer['client_id'];
    }

    private function launch($payload)
    {
        $jwt = $this->buildJWT($payload, $this->issuer);
        if (isset($payload['nonce'])) {
            $this->cache->cacheNonce($payload['nonce'], static::STATE);
        }

        $params = [
            'utf8' => '✓',
            'id_token' => $jwt,
            'state' => static::STATE,
        ];

        $request = Mockery::mock(Response::class);
        $this->serviceConnector->shouldReceive('makeRequest')
            ->once()->andReturn($request);
        $this->serviceConnector->shouldReceive('getResponseBody')
            ->once()->andReturn(json_decode(file_get_contents(static::JWKS_FILE), true));

        return LtiMessageLaunch::new($this->db, $this->cache, $this->cookie, $this->serviceConnector)
            ->validate($params);
    }
}
