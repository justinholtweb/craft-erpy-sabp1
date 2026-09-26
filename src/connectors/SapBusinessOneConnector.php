<?php

namespace justinholtweb\erpysapb1\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\SessionAuth;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpPrice;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\canonical\ErpStock;
use justinholtweb\erpysapb1\transport\ServiceLayerTransport;

/**
 * SAP Business One, through the Service Layer.
 *
 * Three things about B1 are worth stating plainly, because each of them is a trap.
 *
 * **Sessions are licensed.** Every `/Login` consumes a licence slot until it times out, so the
 * session cookie is established once, cached, shared across a run and explicitly closed. An
 * integration that logs in per request will exhaust a small licence in an afternoon.
 *
 * **Booleans are strings.** B1 answers `tYES` and `tNO`, not `true` and `false`, and `(bool)'tNO'`
 * is `true` — which is how an integration ends up publishing every frozen item on the storefront.
 *
 * **Dates and times are separate columns.** `UpdateDate` has no time in it, so a delta sync can
 * only ever be day-granular. This connector therefore asks for everything changed on or after the
 * watermark's *date*, and lets Erpy's content hashing discard the rest — re-reading a day's items
 * is cheap; missing an afternoon's price change is not.
 *
 * Two smaller things. An on-premise Service Layer often has a self-signed certificate; the
 * setting for it is honoured by `ServiceLayerTransport`, on both the session login and every
 * request after it. And contract pricing is B1's `SpecialPrices` table only. Price lists other
 * than the base one are not synced, so a business partner whose B1 price list differs from the
 * base list sees base prices on the storefront unless they also have Special Prices — and the
 * order goes to B1 at the price the storefront charged.
 */
class SapBusinessOneConnector extends Connector
{
    /** The auth strategy whose login transport has already been given the certificate setting. */
    private ?AuthInterface $preparedAuth = null;

    public static function handle(): string
    {
        return 'sap-business-one';
    }

    public static function displayName(): string
    {
        return 'SAP Business One';
    }

    public static function vendor(): string
    {
        return 'SAP';
    }

    public static function description(): string
    {
        return 'SAP Business One through the Service Layer, with one shared session so the licence is not spent on logins.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://help.sap.com/docs/SAP_BUSINESS_ONE_ENVIRONMENT_FOR_SAP_HANA/68a2e87fb29941b5bf959a184d9c6727/de3ec4d2fef44e2c8e97a8fca08d2a6c.html';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            // Day-granular only: B1 keeps the update date and the update time in different
            // columns, and only the date is filterable.
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRICE, Direction::PULL, delta: false, pageSize: 100)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::SHIPMENT, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 100)
            ->withMultiCompany();
    }

    public static function settingsFields(): array
    {
        return [
            Field::url('serviceLayerUrl', Craft::t('erpy', 'Service Layer URL'), [
                'required' => true,
                'placeholder' => 'https://sap.example.com:50000/b1s/v1',
                'instructions' => Craft::t('erpy', 'Include the /b1s/v1 path. Port 50000 is the default for the Service Layer.'),
            ]),
            Field::text('companyDb', Craft::t('erpy', 'Company database'), [
                'required' => true,
                'placeholder' => 'SBODemoUS',
            ]),
            Field::text('username', Craft::t('erpy', 'B1 user'), ['required' => true]),
            Field::secret('password', Craft::t('erpy', 'Password'), ['required' => true]),

            Field::heading(Craft::t('erpy', 'Behaviour')),
            Field::text('warehouse', Craft::t('erpy', 'Warehouse code'), [
                'instructions' => Craft::t('erpy', 'Stock is read from this warehouse and order lines are raised against it. Leave blank for the company-wide figure.'),
            ]),
            Field::text('priceListNum', Craft::t('erpy', 'Base price list number'), [
                'default' => '1',
                'instructions' => Craft::t('erpy', 'The list whose prices become the Commerce base price. Other B1 price lists are not synced; customer-specific prices come from Special Prices for Business Partners.'),
            ]),
            Field::text('seriesNumber', Craft::t('erpy', 'Document series'), [
                'instructions' => Craft::t('erpy', 'The numbering series new sales orders use. Leave blank for the default.'),
            ]),
            Field::boolean('ignoreSslErrors', Craft::t('erpy', 'This Service Layer uses a self-signed certificate'), [
                'instructions' => Craft::t('erpy', 'Turns off certificate verification for this connection only — the login and every request. Leave off unless you know it does: it usually means an on-premise install that has never had a real certificate, and a real one is the better fix.'),
                'default' => false,
            ]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new SessionAuth(
            login: function($connection, Transport $http): ?array {
                $response = $http->request('POST', $this->baseUrl() . '/Login', [
                    'json' => [
                        'CompanyDB' => $connection->getSetting('companyDb'),
                        'UserName' => $connection->getSetting('username'),
                        'Password' => $connection->getSetting('password'),
                    ],
                    'headers' => ['Content-Type' => 'application/json'],
                ]);

                if (!$response->ok()) {
                    return ['error' => $response->errorMessage()];
                }

                $sessionId = (string)$response->at('SessionId', '');

                if ($sessionId === '') {
                    return ['error' => 'The Service Layer accepted the login but returned no session id.'];
                }

                // B1 answers with a session timeout in minutes and expects both cookies back —
                // ROUTEID matters on a load-balanced install, where dropping it lands the next
                // request on a node that has never heard of the session.
                $cookie = 'B1SESSION=' . $sessionId;
                $routeId = $this->routeIdFrom($response->headers);

                if ($routeId !== null) {
                    $cookie .= '; ' . $routeId;
                }

                return [
                    'cookie' => $cookie,
                    'ttl' => max(60, ((int)$response->at('SessionTimeout', 30)) * 60),
                ];
            },
            logout: function($connection, Transport $http, string $cookie): void {
                $http->request('POST', $this->baseUrl() . '/Logout', ['headers' => ['Cookie' => $cookie]]);
            },
            requiredFields: ['serviceLayerUrl', 'companyDb', 'username', 'password'],
        );
    }

    /**
     * Erpy gives the session strategy a plain transport of its own for `/Login` and `/Logout`.
     * On a self-signed Service Layer that one has to skip certificate verification too, or the
     * login fails before any request that would have skipped it is made. It is swapped once per
     * strategy, so a test double installed afterwards is left alone.
     */
    public function auth(): ?AuthInterface
    {
        $auth = parent::auth();

        if ($auth !== null && $auth !== $this->preparedAuth) {
            $this->preparedAuth = $auth;

            if ($this->boolSetting('ignoreSslErrors') && method_exists($auth, 'setTransport')) {
                $auth->setTransport(
                    (new ServiceLayerTransport())
                        ->skipCertificateCheck()
                        ->setConnection($this->connection)
                        ->setSecretValues($this->secretValues()),
                );
            }
        }

        return $auth;
    }

    protected function buildTransport(): Transport
    {
        return (new ServiceLayerTransport())
            ->skipCertificateCheck($this->boolSetting('ignoreSslErrors'))
            ->setBaseUri($this->baseUrl())
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // Without this the Service Layer returns 20 rows and ignores $top entirely.
                'Prefer' => 'odata.maxpagesize=0',
            ])
            ->setRateLimit(4)
            ->setTimeout(120);
    }

    protected function probe(): HealthResult
    {
        $response = $this->transport()->get('CompanyService_GetCompanyInfo');

        if (!$response->ok()) {
            // Not every B1 version exposes the company info service; fall back to something that
            // certainly exists rather than reporting a false failure.
            $response = $this->transport()->get('Items', ['$top' => 1, '$select' => 'ItemCode']);
        }

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), match (true) {
                $response->status === 401 => [Craft::t('erpy', 'Check the company database name — it is case-sensitive, and a wrong one fails exactly like a wrong password.')],
                $response->status === 0 => [Craft::t('erpy', 'Nothing answered. Check the host and port are reachable from this server, and whether the Service Layer is using a self-signed certificate.')],
                default => [],
            });
        }

        return HealthResult::pass(Craft::t('erpy', 'Connected to SAP Business One.'), array_filter([
            Craft::t('erpy', 'Company') => (string)($response->at('CompanyName') ?: $this->setting('companyDb')),
            Craft::t('erpy', 'Version') => (string)($response->at('Version') ?: ''),
        ]));
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        $basePriceList = (int)$this->setting('priceListNum', 1);

        return $this->page('Items', $criteria, Entity::PRODUCT, function(array $row) use ($basePriceList): ErpProduct {
            $price = null;

            foreach ((array)($row['ItemPrices'] ?? []) as $itemPrice) {
                if ((int)($itemPrice['PriceList'] ?? 0) === $basePriceList) {
                    $price = isset($itemPrice['Price']) ? (float)$itemPrice['Price'] : null;
                    break;
                }
            }

            return new ErpProduct([
                'sku' => (string)($row['ItemCode'] ?? ''),
                'name' => (string)($row['ItemName'] ?? ''),
                'description' => $row['User_Text'] ?? null,
                'enabled' => $this->yes($row['Valid'] ?? 'tYES') && !$this->yes($row['Frozen'] ?? 'tNO'),
                'blocked' => $this->yes($row['Frozen'] ?? 'tNO'),
                'category' => isset($row['ItemsGroupCode']) ? (string)$row['ItemsGroupCode'] : null,
                'unitOfMeasure' => $row['SalesUnit'] ?? $row['InventoryUOM'] ?? null,
                'price' => $price,
                'barcode' => $row['BarCode'] ?: null,
                'weight' => isset($row['SalesUnitWeight']) ? (float)$row['SalesUnitWeight'] : null,
                'tracksInventory' => $this->yes($row['InventoryItem'] ?? 'tYES'),
                'remoteId' => (string)($row['ItemCode'] ?? ''),
                'remoteKey' => (string)($row['ItemCode'] ?? ''),
                'modifiedAt' => $this->date($row['UpdateDate'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UpdateDate');
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        $warehouse = (string)$this->setting('warehouse', '');

        return $this->page('Items', $criteria, Entity::INVENTORY, function(array $row) use ($warehouse): ErpStock {
            $onHand = (float)($row['QuantityOnStock'] ?? 0);
            $committed = (float)($row['QuantityOrderedByCustomers'] ?? 0);
            $ordered = (float)($row['QuantityOrderedFromVendors'] ?? 0);

            // A warehouse-specific figure comes from the item's own warehouse collection; the
            // company-wide numbers on the header ignore which site the stock is actually at.
            if ($warehouse !== '') {
                foreach ((array)($row['ItemWarehouseInfoCollection'] ?? []) as $info) {
                    if ((string)($info['WarehouseCode'] ?? '') === $warehouse) {
                        $onHand = (float)($info['InStock'] ?? 0);
                        $committed = (float)($info['Committed'] ?? 0);
                        $ordered = (float)($info['Ordered'] ?? 0);
                        break;
                    }
                }
            }

            return new ErpStock([
                'sku' => (string)($row['ItemCode'] ?? ''),
                'warehouse' => $warehouse ?: null,
                'onHand' => $onHand,
                'allocated' => $committed,
                'incoming' => $ordered,
                'remoteId' => (string)($row['ItemCode'] ?? ''),
                'modifiedAt' => $this->date($row['UpdateDate'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UpdateDate', select: 'ItemCode,QuantityOnStock,QuantityOrderedByCustomers,QuantityOrderedFromVendors,UpdateDate,ItemWarehouseInfoCollection');
    }

    protected function fetchPrices(FetchCriteria $criteria): Page
    {
        // SpecialPrices is B1's customer-specific price table, which is exactly the shape Erpy's
        // contract pricing wants. Price-list prices arrive with the item itself.
        return $this->page('SpecialPrices', $criteria, Entity::PRICE, function(array $row): ErpPrice {
            return new ErpPrice([
                'sku' => (string)($row['ItemCode'] ?? ''),
                'customerCode' => (string)($row['CardCode'] ?? ''),
                'currency' => $row['Currency'] ?? null,
                'unitPrice' => (float)($row['Price'] ?? 0),
                'discountPercent' => isset($row['Discount']) ? (float)$row['Discount'] : null,
                'minQuantity' => 1.0,
                'startsAt' => $this->date($row['ValidFrom'] ?? null),
                'endsAt' => $this->date($row['ValidTo'] ?? null),
                'remoteId' => ($row['CardCode'] ?? '') . ':' . ($row['ItemCode'] ?? ''),
                'raw' => $row,
            ]);
        }, deltaField: null);
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->page('BusinessPartners', $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            $addresses = [];

            foreach ((array)($row['BPAddresses'] ?? []) as $address) {
                $addresses[] = new ErpAddress([
                    'type' => (string)($address['AddressType'] ?? '') === 'bo_ShipTo'
                        ? ErpAddress::TYPE_SHIPPING
                        : ErpAddress::TYPE_BILLING,
                    'code' => $address['AddressName'] ?? null,
                    'fullName' => (string)($row['CardName'] ?? ''),
                    'addressLine1' => $address['Street'] ?? null,
                    'addressLine2' => $address['Block'] ?? null,
                    'locality' => $address['City'] ?? null,
                    'administrativeArea' => $address['State'] ?? null,
                    'postalCode' => $address['ZipCode'] ?? null,
                    'countryCode' => $address['Country'] ?? null,
                ]);
            }

            return new ErpCustomer([
                'code' => (string)($row['CardCode'] ?? ''),
                'name' => (string)($row['CardName'] ?? ''),
                'email' => $row['EmailAddress'] ?: null,
                'phone' => $row['Phone1'] ?: null,
                'website' => $row['Website'] ?: null,
                'enabled' => $this->yes($row['Valid'] ?? 'tYES'),
                'onHold' => $this->yes($row['Frozen'] ?? 'tNO'),
                'currency' => $row['Currency'] ?: null,
                'priceListCode' => isset($row['PriceListNum']) ? (string)$row['PriceListNum'] : null,
                'customerGroupCode' => isset($row['GroupCode']) ? (string)$row['GroupCode'] : null,
                'paymentTermsCode' => isset($row['PayTermsGrpCode']) ? (string)$row['PayTermsGrpCode'] : null,
                'taxId' => $row['FederalTaxID'] ?: null,
                'creditLimit' => isset($row['CreditLimit']) ? (float)$row['CreditLimit'] : null,
                'balance' => isset($row['CurrentAccountBalance']) ? (float)$row['CurrentAccountBalance'] : null,
                'discountPercent' => isset($row['DiscountPercent']) ? (float)$row['DiscountPercent'] : null,
                'addresses' => $addresses,
                'remoteId' => (string)($row['CardCode'] ?? ''),
                'remoteKey' => (string)($row['CardCode'] ?? ''),
                'modifiedAt' => $this->date($row['UpdateDate'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UpdateDate', extraFilters: ["CardType eq 'cCustomer'"]);
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        return $this->page('BusinessPartners', $criteria, Entity::CREDIT, function(array $row): ErpCredit {
            return new ErpCredit([
                'customerCode' => (string)($row['CardCode'] ?? ''),
                'currency' => (string)($row['Currency'] ?: 'USD'),
                'creditLimit' => isset($row['CreditLimit']) ? (float)$row['CreditLimit'] : null,
                'balance' => (float)($row['CurrentAccountBalance'] ?? 0),
                // B1 tracks the value of open deliveries separately, and it counts against the
                // limit just as an unpaid invoice does.
                'openOrders' => (float)($row['OpenDeliveryNotesBalance'] ?? 0) + (float)($row['OpenOrdersBalance'] ?? 0),
                'onHold' => $this->yes($row['Frozen'] ?? 'tNO'),
                'paymentTermsCode' => isset($row['PayTermsGrpCode']) ? (string)$row['PayTermsGrpCode'] : null,
                'raw' => $row,
            ]);
        }, deltaField: null, extraFilters: ["CardType eq 'cCustomer'"], select: 'CardCode,Currency,CreditLimit,CurrentAccountBalance,OpenDeliveryNotesBalance,OpenOrdersBalance,Frozen,PayTermsGrpCode');
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->page('Orders', $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            $status = (string)($row['DocumentStatus'] ?? '');
            $cancelled = $this->yes($row['Cancelled'] ?? 'tNO');

            return new ErpOrderStatus([
                // NumAtCard is B1's "customer reference number" field, which is where the
                // Commerce order number went out.
                'orderNumber' => (string)($row['NumAtCard'] ?? ''),
                'status' => $status,
                'statusCode' => $status,
                'isCancelled' => $cancelled,
                'isClosed' => $status === 'bost_Close',
                // B1 closes a sales order when it has been fully delivered, so "closed" is the
                // only signal it gives that the goods have gone.
                'isShipped' => $status === 'bost_Close' && !$cancelled,
                'remoteId' => (string)($row['DocEntry'] ?? ''),
                'remoteKey' => (string)($row['DocNum'] ?? ''),
                'modifiedAt' => $this->date($row['UpdateDate'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UpdateDate', select: 'DocEntry,DocNum,NumAtCard,DocumentStatus,Cancelled,UpdateDate');
    }

    protected function fetchShipments(FetchCriteria $criteria): Page
    {
        return $this->page('DeliveryNotes', $criteria, Entity::SHIPMENT, function(array $row): ErpShipment {
            $lines = [];

            foreach ((array)($row['DocumentLines'] ?? []) as $line) {
                $lines[] = [
                    'sku' => (string)($line['ItemCode'] ?? ''),
                    'quantity' => (float)($line['Quantity'] ?? 0),
                ];
            }

            return new ErpShipment([
                'orderNumber' => (string)($row['NumAtCard'] ?? ''),
                'shipmentNumber' => (string)($row['DocNum'] ?? $row['DocEntry'] ?? ''),
                'trackingNumber' => $row['TrackingNumber'] ?: null,
                'carrier' => isset($row['TransportationCode']) ? (string)$row['TransportationCode'] : null,
                'shippedAt' => $this->date($row['DocDate'] ?? null),
                'lines' => $lines,
                'remoteId' => (string)($row['DocEntry'] ?? ''),
                'modifiedAt' => $this->date($row['UpdateDate'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UpdateDate');
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        return $this->page('Invoices', $criteria, Entity::INVOICE, function(array $row): ErpInvoice {
            $total = (float)($row['DocTotal'] ?? 0);
            $paid = (float)($row['PaidToDate'] ?? 0);

            return new ErpInvoice([
                'invoiceNumber' => (string)($row['DocNum'] ?? ''),
                'orderNumber' => (string)($row['NumAtCard'] ?? ''),
                'customerCode' => (string)($row['CardCode'] ?? ''),
                'issuedAt' => $this->date($row['DocDate'] ?? null),
                'dueAt' => $this->date($row['DocDueDate'] ?? null),
                'currency' => (string)($row['DocCurrency'] ?: 'USD'),
                'subtotal' => (float)($row['DocTotal'] ?? 0) - (float)($row['VatSum'] ?? 0),
                'taxTotal' => (float)($row['VatSum'] ?? 0),
                'total' => $total,
                'amountPaid' => $paid,
                'balance' => $total - $paid,
                'isPaid' => abs($total - $paid) < 0.005,
                'status' => (string)($row['DocumentStatus'] ?? ''),
                'remoteId' => (string)($row['DocEntry'] ?? ''),
                'modifiedAt' => $this->date($row['UpdateDate'] ?? null),
                'raw' => $row,
            ]);
        }, deltaField: 'UpdateDate');
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'SAP Business One needs a business partner code. Set a guest customer code on the order mapping, or link this customer to a B1 partner.'));
        }

        // B1 has no idempotency key, so the customer reference number does the job. It is indexed
        // and it is what a merchant will search for when asked "did this order arrive?".
        $existing = $this->findOrder($this->numAtCard($document->orderNumber));

        if ($existing !== null && $remoteId === null) {
            return PushResult::alreadyExists((string)($existing['DocEntry'] ?? ''), (string)($existing['DocNum'] ?? ''));
        }

        $warehouse = (string)$this->setting('warehouse', '');
        $lines = [];

        foreach ($document->lines as $line) {
            $lines[] = array_filter([
                'ItemCode' => $line->sku,
                'Quantity' => $line->quantity,
                'UnitPrice' => $line->unitPrice,
                'DiscountPercent' => $line->discountPercent,
                'WarehouseCode' => $line->warehouse ?: ($warehouse ?: null),
                'FreeText' => $line->notes ? mb_substr(implode('; ', $line->notes), 0, 254) : null,
            ], static fn($value) => $value !== null && $value !== '');
        }

        $payload = array_filter([
            'CardCode' => $document->customerCode,
            'NumAtCard' => $this->numAtCard($document->orderNumber),
            'DocDate' => ($document->orderedAt ?? new DateTime())->format('Y-m-d'),
            'DocDueDate' => ($document->requestedDeliveryAt ?? $document->orderedAt ?? new DateTime())->format('Y-m-d'),
            'DocCurrency' => $document->currency,
            'Comments' => $document->customerNote ? mb_substr($document->customerNote, 0, 254) : null,
            'Series' => $this->setting('seriesNumber') ? (int)$this->setting('seriesNumber') : null,
            'DocumentLines' => $lines,
        ], static fn($value) => $value !== null && $value !== '');

        if ($document->shippingAddress && !$document->shippingAddress->isEmpty()) {
            $payload['AddressExtension'] = array_filter([
                'ShipToStreet' => $document->shippingAddress->addressLine1,
                'ShipToBlock' => $document->shippingAddress->addressLine2,
                'ShipToCity' => $document->shippingAddress->locality,
                'ShipToState' => $document->shippingAddress->administrativeArea,
                'ShipToZipCode' => $document->shippingAddress->postalCode,
                'ShipToCountry' => $document->shippingAddress->countryCode,
            ], static fn($value) => $value !== null && $value !== '');
        }

        foreach ($document->customFields as $field => $value) {
            $payload[$field] = $value;
        }

        $response = $this->transport()->post('Orders', $payload);

        if (!$response->ok()) {
            return $response->status >= 400 && $response->status < 500
                ? PushResult::rejected($response->errorMessage(), $response->json_())
                : PushResult::failed($response->errorMessage(), $response->json_());
        }

        return PushResult::ok(
            (string)$response->at('DocEntry', ''),
            (string)$response->at('DocNum', ''),
            $response->json_(),
        );
    }

    /**
     * The value written to `NumAtCard`, looked up by a retry and compared against what comes
     * back: the Commerce number, cut to the field's 100 characters.
     */
    private function numAtCard(string $orderNumber): string
    {
        return mb_substr($orderNumber, 0, 100);
    }

    /**
     * The order already carrying this `NumAtCard`, or null. Every returned row is compared as
     * well as filtered for: a Service Layer that ignores or mis-applies `$filter` must not turn
     * every order after the first into a duplicate of whatever it returned.
     */
    private function findOrder(string $numAtCard): ?array
    {
        if ($numAtCard === '') {
            return null;
        }

        $response = $this->transport()->get('Orders', [
            '$filter' => "NumAtCard eq '" . $this->escape($numAtCard) . "'",
            '$select' => 'DocEntry,DocNum,NumAtCard',
            '$top' => 20,
        ]);

        $rows = $response->ok() ? $response->at('value', []) : [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && (string)($row['NumAtCard'] ?? '') === $numAtCard) {
                return $row;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    private function page(
        string $collection,
        FetchCriteria $criteria,
        string $entity,
        callable $make,
        ?string $deltaField = null,
        ?string $select = null,
        array $extraFilters = [],
    ): Page {
        if ($criteria->cursor !== null) {
            $response = $this->transport()->get($criteria->cursor);
        } else {
            $query = ['$top' => $this->pageSize($entity, $criteria)];

            if ($select !== null) {
                $query['$select'] = $select;
            }

            $filters = $extraFilters;

            if ($deltaField !== null && $criteria->since instanceof DateTimeInterface) {
                // Date only, and deliberately so: B1 keeps UpdateDate and UpdateTime in separate
                // columns and only the date is filterable, so the day is the finest grain
                // available. Re-reading a day of items is cheap; missing one is not.
                $filters[] = sprintf("%s ge '%s'", $deltaField, $criteria->since->format('Y-m-d'));
            }

            foreach ($criteria->filters as $field => $value) {
                $filters[] = sprintf("%s eq '%s'", $field, $this->escape((string)$value));
            }

            if ($filters !== []) {
                $query['$filter'] = implode(' and ', $filters);
            }

            $response = $this->transport()->get($collection, $query);
        }

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf(
                'The Service Layer refused to read %s: %s',
                $collection,
                $response->errorMessage(),
            ));
        }

        $items = [];

        foreach ($response->at('value', []) as $row) {
            if (is_array($row)) {
                $items[] = $make($row);
            }
        }

        return new Page($items, $response->at('odata.nextLink') ?? $response->at('@odata.nextLink'));
    }

    /**
     * B1 answers booleans as `tYES` and `tNO`. `(bool)'tNO'` is true, which is how an integration
     * ends up publishing every frozen item in the catalogue.
     */
    private function yes(mixed $value): bool
    {
        return strtoupper((string)$value) === 'TYES' || $value === true;
    }

    private function baseUrl(): string
    {
        return rtrim((string)$this->setting('serviceLayerUrl'), '/');
    }

    /**
     * On a load-balanced Service Layer the session only exists on one node, and ROUTEID is what
     * gets the next request back to it.
     */
    private function routeIdFrom(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            if (strcasecmp((string)$name, 'Set-Cookie') !== 0) {
                continue;
            }

            foreach ((array)$values as $value) {
                if (stripos((string)$value, 'ROUTEID=') === 0) {
                    return strtok((string)$value, ';') ?: null;
                }
            }
        }

        return null;
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
