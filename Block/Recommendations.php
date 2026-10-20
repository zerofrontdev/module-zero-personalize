<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Personalize\Block;

use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Customer\Model\Session;
use Magento\Framework\DataObject\IdentityInterface;
use Zero\Personalize\Model\Config;
use Zero\Personalize\Model\Results;

/**
 * A row of recommended products, from the last training run's results:
 * - mode "similar": the product page's product's similar ones (in the
 *   page cache, with the shown products' tags and CACHE_TAG, which a run
 *   cleans);
 * - mode "for_you": the signed-in customer's picks (pages that aren't
 *   cached: My Account, the cart; on the home page through the fragment
 *   Controller\ForYou\Index).
 * Products not for sale in this store (disabled, out of stock, not
 * visible) are left out; nothing to show, nothing rendered.
 */
class Recommendations extends AbstractProduct implements IdentityInterface
{
    public const CACHE_TAG = 'zero_personalize';

    /** @var Product[]|null */
    private ?array $items = null;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Results $results,
        private readonly CollectionFactory $collectionFactory,
        private readonly Visibility $visibility,
        private readonly Session $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function mode(): string
    {
        return $this->getData('mode') === 'for_you' ? 'for_you' : 'similar';
    }

    public function title(): string
    {
        $title = $this->mode() === 'for_you' ? $this->config->userTitle() : $this->config->similarTitle();
        return $title !== '' ? $title : (string) ($this->mode() === 'for_you' ? __('Picked for you') : __('You may also like'));
    }

    /**
     * The title as the theme sets headings: a lead and an italic last word.
     *
     * @return array{lead: string, accent: string}
     */
    public function heading(): array
    {
        $words = preg_split('/\s+/u', trim($this->title())) ?: [];
        $accent = count($words) > 1 ? (string) array_pop($words) : '';
        return ['lead' => implode(' ', $words), 'accent' => $accent];
    }

    /**
     * @return Product[]
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $this->items = [];
        if ($this->mode() === 'for_you') {
            $customerId = (int) $this->customerSession->getCustomerId();
            if (!$this->config->isUserShown() || $customerId <= 0) {
                return $this->items;
            }
            $limit = $this->config->userCount();
            $ids = $this->results->forCustomer($customerId, $limit * 2);
        } else {
            $product = $this->getProduct();
            if (!$this->config->isSimilarShown() || !$product || !$product->getId()) {
                return $this->items;
            }
            $limit = $this->config->similarCount();
            $ids = array_values(array_diff($this->results->similar((int) $product->getId(), $limit * 2), [(int) $product->getId()]));
        }
        if (!$ids) {
            return $this->items;
        }
        $products = $this->load($ids);
        foreach ($ids as $id) {
            if (isset($products[$id]) && $products[$id]->isSalable()) {
                $this->items[] = $products[$id];
            }
            if (count($this->items) === $limit) {
                break;
            }
        }
        return $this->items;
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        $tags = [self::CACHE_TAG];
        foreach ($this->items() as $product) {
            $tags = array_merge($tags, $product->getIdentities());
        }
        return $tags;
    }

    /**
     * @param int[] $ids
     * @return array<int, Product>
     */
    private function load(array $ids): array
    {
        $collection = $this->collectionFactory->create()
            ->addStoreFilter()
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addAttributeToSelect(['name', 'small_image', 'thumbnail', 'price', 'special_price', 'special_from_date', 'special_to_date', 'required_options', 'tax_class_id', 'url_key', 'status'])
            ->addIdFilter($ids)
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite();
        $products = [];
        foreach ($collection as $product) {
            $products[(int) $product->getId()] = $product;
        }
        return $products;
    }
}
