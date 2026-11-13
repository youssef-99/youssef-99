<?php

namespace App\Services;

use App\Exceptions\ErpException;
use App\Models\Customer;
use App\Models\CustomerTransfer;
use Illuminate\Support\Facades\DB;

/**
 * Transfer customers between salesmen (تحويل العملاء بين المندوبين).
 */
class CustomerTransferService
{
    /**
     * Move customers of $fromSalesmanId (all of them when $customerIds is empty) to $toSalesmanId
     * and keep a CustomerTransfer record of the move.
     *
     * @param  array<int|string>  $customerIds
     */
    public static function transfer(?int $fromSalesmanId, int $toSalesmanId, array $customerIds = []): CustomerTransfer
    {
        if ($fromSalesmanId !== null && $fromSalesmanId === $toSalesmanId) {
            throw new ErpException(__('Choose two different salesmen.'));
        }

        $customerIds = array_values(array_filter(array_map('intval', $customerIds)));

        if ($fromSalesmanId === null && $customerIds === []) {
            throw new ErpException(__('Select the customers to transfer.'));
        }

        $query = Customer::query()
            ->when($fromSalesmanId, fn ($q, $id) => $q->where('salesman_id', $id))
            ->when($customerIds !== [], fn ($q) => $q->whereKey($customerIds));

        $ids = $query->pluck('id')->all();

        if ($ids === []) {
            throw new ErpException(__('There are no customers to transfer.'));
        }

        if ($customerIds !== [] && count($ids) !== count($customerIds)) {
            throw new ErpException(__('Some of the selected customers do not belong to the source salesman.'));
        }

        return DB::transaction(function () use ($fromSalesmanId, $toSalesmanId, $ids) {
            Customer::query()->whereKey($ids)->update(['salesman_id' => $toSalesmanId, 'updated_at' => now()]);

            return CustomerTransfer::query()->create([
                'from_salesman_id' => $fromSalesmanId,
                'to_salesman_id' => $toSalesmanId,
                'customer_ids' => $ids,
                'created_by' => auth()->id(),
            ]);
        });
    }
}
