<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Canned Client Portal API payloads, shaped like the real gateway including its quirks.
 * Kept in one place so a change in what the gateway actually returns is a single edit.
 */
class IbkrFakeResponses
{
    /**
     * @return array<string, mixed>
     */
    public static function authenticated(bool $authenticated = true): array
    {
        return [
            'authenticated' => $authenticated,
            'competing' => false,
            'connected' => true,
            'MAC' => '00:00:00:00:00:00',
        ];
    }

    /**
     * A placed order. The gateway answers with a list even for a single order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function orderPlaced(string $orderId = 'ORD-001'): array
    {
        return [[
            'order_id' => $orderId,
            'order_status' => 'PreSubmitted',
            'encrypt_message' => '1',
        ]];
    }

    /**
     * The confirmation challenge the gateway raises instead of placing the order outright.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function orderConfirmationChallenge(string $replyId = 'REPLY-1'): array
    {
        return [[
            'id' => $replyId,
            'messageIds' => ['o163'],
            'message' => ['You are submitting an order without market data.'],
            'isSuppressed' => false,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function authFailure(): array
    {
        return ['error' => 'not authenticated', 'statusCode' => 401];
    }

    /**
     * Open and recent orders as returned by /iserver/account/orders.
     *
     * @return array<string, mixed>
     */
    public static function orderStatus(
        string $orderId = 'ORD-001',
        string $status = 'Filled',
        string $avgPrice = '115.00',
        ?float $filledQuantity = null,
    ): array {
        $order = [
            'orderId' => $orderId,
            'status' => $status,
            'avgPrice' => $avgPrice,
            'ticker' => 'AAPL',
            'side' => 'SELL',
        ];

        if ($filledQuantity !== null) {
            $order['filledQuantity'] = $filledQuantity;
        }

        return ['orders' => [$order]];
    }

    /**
     * A market data snapshot. Field 31 is the last traded price.
     *
     * @param  array<int|string, string>  $pricesByConid
     * @return array<int, array<string, mixed>>
     */
    public static function snapshot(array $pricesByConid): array
    {
        $rows = [];

        foreach ($pricesByConid as $conid => $price) {
            $rows[] = [
                'conid' => (int) $conid,
                '31' => $price,
                '_updated' => 1754000000000,
                '6119' => 'q0',
            ];
        }

        return $rows;
    }

    /**
     * The gateway answers the first request for a new conid without a price, so a caller has
     * to ask again. This is the shape it returns in the meantime.
     *
     * @param  array<int, int>  $conids
     * @return array<int, array<string, mixed>>
     */
    public static function snapshotNotSubscribedYet(array $conids): array
    {
        return array_map(fn (int $conid): array => [
            'conid' => $conid,
            '_updated' => 1754000000000,
        ], $conids);
    }

    /**
     * Portfolio positions as returned by /portfolio/{accountId}/positions/0.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function portfolioPositions(): array
    {
        return [
            [
                'acctId' => 'DU0000001',
                'conid' => 265598,
                'ticker' => 'AAPL',
                'contractDesc' => 'AAPL',
                'position' => 12.0,
                'avgCost' => 178.42,
                'currency' => 'USD',
                'assetClass' => 'STK',
            ],
            [
                'acctId' => 'DU0000001',
                'conid' => 76792991,
                'ticker' => 'TSLA',
                'contractDesc' => 'TSLA',
                'position' => 4.0,
                'avgCost' => 201.15,
                'currency' => 'USD',
                'assetClass' => 'STK',
            ],
        ];
    }
}
