{if isset($products) && $products}
    <div id="related_products" class="block products_block clearfix">
        <h4 class="title_block">{l s='You may also like' mod='associator'}</a></h4>
        {include file="$tpl_dir./product-list.tpl" class="related_products tab-pane" id="related_products" products=$products}
    </div>
{/if}
