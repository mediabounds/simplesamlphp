<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\saml\Controller;

use PHPUnit\Framework\TestCase;
use SimpleSAML\{Configuration, Error, Session, Utils};
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\saml\Controller;
use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * Set of tests for the controllers in the "saml" module.
 *
 * @covers \SimpleSAML\Module\saml\Controller\Metadata
 * @package SimpleSAML\Test
 */
class MetadataTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Utils\Auth */
    protected Utils\Auth $authUtils;

    protected MetaDataStorageHandler $mdh;
    /**
     * Set up for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => ['saml' => true],
                'enable.saml20-idp' => true,
                'admin.protectmetadata' => false,
            ],
            '[ARRAY]',
            'simplesaml'
        );
        Configuration::setPreLoadedConfig($this->config, 'config.php');

        $this->mdh = new class ($this->config) extends MetaDataStorageHandler {
            /** @var string */
            private const XMLSEC = '../vendor/simplesamlphp/xml-security/resources';

            /** @var string */
            public const CERT_KEY = self::XMLSEC . '/certificates/selfsigned.simplesamlphp.org.key';

            /** @var string */
            public const CERT_PUBLIC = self::XMLSEC . '/certificates/selfsigned.simplesamlphp.org.crt';

            private array $idps;

            public function __construct(Configuration $config)
            {
                parent::__construct($config);

                $this->idps = [
                    'urn:example:simplesaml:idp' => [
                        'name' => 'SimpleSAMLphp Hosted IDP',
                        'descr' => 'The local IDP',
                        'OrganizationDisplayName' => ['en' => 'My IDP', 'nl' => 'Mijn IDP'],
                        'certificate' => self::CERT_PUBLIC,
                        'privatekey' => self::CERT_KEY,
                    ],

                    'urn:example:simplesaml:another:idp' => [
                        'name' => 'SimpleSAMLphp Hosted Another IDP',
                        'descr' => 'Different IDP',
                        'OrganizationDisplayName' => ['en' => 'Other IDP', 'nl' => 'Andere IDP'],
                        'certificate' => self::CERT_PUBLIC,
                        'privatekey' => self::CERT_KEY,
                    ],
                ];
            }

            public function getMetaData(?string $entityId, string $set): array
            {
                if (isset($this->idps[$entityId]) && $set === 'saml20-idp-hosted') {
                    return $this->idps[$entityId];
                }

                throw new Error\MetadataNotFound($entityId ?? '');
            }

            public function getList(string $set = 'saml20-idp-remote', bool $showExpired = false): array
            {
                if ($set === 'saml20-idp-hosted') {
                    return $this->idps;
                }
                return [];
            }

            public function getMetaDataCurrentEntityID(string $set, string $type = 'entityid'): string
            {
                return 'urn:example:simplesaml:another:idp';
            }
        };

        Configuration::setPreLoadedConfig(
            Configuration::loadFromArray(
                [
                    'admin' => ['core:AdminPassword'],
                    'phpunit' => ['saml:SP'],
                ],
                '[ARRAY]',
                'simplesaml'
            ),
            'authsources.php',
            'simplesaml'
        );

        $this->authUtils = new class () extends Utils\Auth {
            public function requireAdmin(): ?Response
            {
                // stub
                return null;
            }
        };

        $_SERVER['REQUEST_URI'] = '/dummy';
    }


    /**
     * Test that accessing the metadata-endpoint with or without authentication
     * and admin.protectmetadata set to true or false is handled properly
     *
     * @dataProvider provideMetadataAccess
     */
    public function testMetadataAccess(bool $authenticated, bool $protected): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $config = Configuration::loadFromArray(
            [
                'module.enable' => ['saml' => true],
                'enable.saml20-idp' => true,
                'admin.protectmetadata' => $protected,
            ],
            '[ARRAY]',
            'simplesaml'
        );
        Configuration::setPreLoadedConfig($config, 'config.php');

        $request = Request::create(
            '/idp/metadata',
            'GET',
        );

        $c = new Controller\Metadata($config);
        $c->setMetadataStorageHandler($this->mdh);

        // Bypass authentication - mock being authenticated
        $c->setAuthUtils($this->authUtils);

        $result = $c->metadata($request);

        $this->assertInstanceOf(Response::class, $result);
    }

    public static function provideMetadataAccess(): array
    {
        return [
           /* [authenticated, protected] */
           [false, false],
           [false, true],
           [true, false],
           [true, true],
        ];
    }

    /**
     * Test that saml20-idp setting disabled disables access
     */
    public function testDisabledSAML20IDPReturnsNoAccess(): void
    {
        $config = Configuration::loadFromArray(
            [
                'module.enable' => ['saml' => true],
                'enable.saml20-idp' => false,
            ],
            '[ARRAY]',
            'simplesaml'
        );
        Configuration::setPreLoadedConfig($config, 'config.php');

        $request = Request::create(
            '/idp/metadata',
            'GET',
        );

        $c = new Controller\Metadata($config);

        $this->expectException(Error\Error::class);
        $this->expectExceptionMessage(Error\ErrorCodes::NOACCESS);
        $result = $c->metadata($request);
    }

    /**
     * Test that requesting a non-existing entityID throws an exception
     */
    public function testMetadataUnknownEntityThrowsError(): void
    {
        $request = Request::create(
            '/idp/metadata?idpentityid=https://example.org/notexist',
            'GET',
        );

        $c = new Controller\Metadata($this->config);
        $c->setMetadataStorageHandler($this->mdh);

        $this->expectException(Error\Error::class);
        $this->expectExceptionMessage(Error\ErrorCodes::METADATA);
        $result = $c->metadata($request);
    }

    /**
     * Basic smoke test of generated metadata
     */
    public function testMetadataYieldsContent(): void
    {
        $request = Request::create(
            '/idp/metadata?idpentityid=urn:example:simplesaml:idp',
            'GET',
        );

        $c = new Controller\Metadata($this->config);
        $c->setMetadataStorageHandler($this->mdh);

        $result = $c->metadata($request);
        $this->assertEquals('application/samlmetadata+xml', $result->headers->get('Content-Type'));
        $this->assertEquals('attachment; filename="idp-metadata.xml"', $result->headers->get('Content-Disposition'));
        $content = $result->getContent();

        $expect = 'entityID="urn:example:simplesaml:idp"';
        $this->assertStringContainsString($expect, $content);

        $expect = '<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location=';
        $this->assertStringContainsString($expect, $content);
    }

    /**
     * Test not specifying explict entityID falls back to a default
     */
    public function testMetadataDefaultIdPYieldsContent(): void
    {
        $request = Request::create(
            '/idp/metadata',
            'GET',
        );

        $c = new Controller\Metadata($this->config);
        $c->setMetadataStorageHandler($this->mdh);

        $result = $c->metadata($request);
        $this->assertEquals('application/samlmetadata+xml', $result->headers->get('Content-Type'));
        $this->assertEquals('attachment; filename="idp-metadata.xml"', $result->headers->get('Content-Disposition'));
        $content = $result->getContent();

        $expect = 'entityID="urn:example:simplesaml:another:idp"';
        $this->assertStringContainsString($expect, $content);

        $expect = '<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location=';
        $this->assertStringContainsString($expect, $content);
    }

    /**
     * Check if caching headers are set
     */
    public function testMetadataCachingHeaders(): void
    {
        $request = Request::create(
            '/idp/metadata',
            'GET',
        );

        $c = new Controller\Metadata($this->config);
        $c->setMetadataStorageHandler($this->mdh);

        $result = $c->metadata($request);
        $this->assertTrue($result->headers->has('ETag'));
        $this->assertEquals('public', $result->headers->get('Cache-Control'));
    }
}
