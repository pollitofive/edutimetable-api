<?php

namespace App\Services;

use App\Models\Business;

class CurrentBusiness
{
    private ?int $businessId = null;

    private ?Business $business = null;

    /**
     * Set the current business ID
     */
    public function setId(int $businessId): void
    {
        $this->businessId = $businessId;
        $this->business = null; // Reset cached business
    }

    /**
     * Get the current business ID
     */
    public function id(): ?int
    {
        return $this->businessId;
    }

    /**
     * Check if a business ID is set
     */
    public function hasId(): bool
    {
        return $this->businessId !== null;
    }

    /**
     * Get the current business model
     */
    public function get(): ?Business
    {
        if ($this->business === null && $this->businessId !== null) {
            $this->business = Business::find($this->businessId);
        }

        return $this->business;
    }

    /**
     * Set the current business model directly
     */
    public function set(Business $business): void
    {
        $this->business = $business;
        $this->businessId = $business->id;
    }

    /**
     * Clear the current business context
     */
    public function clear(): void
    {
        $this->businessId = null;
        $this->business = null;
    }
}
