{if $status == 'ok'}
    <p class="text-success">{l s='Your order on %s is complete.' sprintf=[$shop_name] mod='ps_targetpay'}</p>
    <p>{l s='Your order information:' mod='ps_targetpay'}</p>
    <dl>
        <dt>{l s='Order number' mod='ps_targetpay'}</dt>
        <dd>{$order->id}</dd>
        <dt>{l s='Amount' mod='ps_targetpay'}</dt>
        <dd>{$total}</dd>
    </dl>
    <strong>{l s='Your order will be sent as soon as we receive payment.' mod='ps_targetpay'}</strong>
    <p>
    {l s='Thank you for shopping. While logged in, you may continue shopping or view your current order status and [1]order history[/1].' mod='ps_targetpay' tags=["<a class='link-button' href=\"{$urls.pages.history}\">"]}
    </p>
{else if $status == 'processing'}
    <p class="text-info">{l s='Your order on %s is processing.' sprintf=[$shop_name] mod='ps_targetpay'}</p>
    <p>{l s='Your order information:' mod='ps_targetpay'}</p>
    <dl>
        <dt>{l s='Order number' mod='ps_targetpay'}</dt>
        <dd>{$order->id}</dd>
        <dt>{l s='Amount' mod='ps_targetpay'}</dt>
        <dd>{$total}</dd>
    </dl>
    <strong>{l s='Payment is under processing. Your order will be sent as soon as we receive payment.' mod='ps_targetpay'}</strong>
    <p>
      {l s='Thank you for shopping. While logged in, you may continue shopping or view your current order status and [1]order history[/1].' mod='ps_targetpay' tags=["<a class='link-button' href=\"{$urls.pages.history}\">"]}
    </p>
{else}
    <p class="text-warning">{l s='Your order on %s is failed.' sprintf=[$shop_name] mod='ps_targetpay'}</p>
    <p>{l s='Your order information:' mod='ps_targetpay'}</p>
    <dl>
        <dt>{l s='Order number' mod='ps_targetpay'}</dt>
        <dd>{$order->id}</dd>
        <dt>{l s='Amount' mod='ps_targetpay'}</dt>
        <dd>{$total}</dd>
    </dl>
    <strong>{l s='We noticed a problem with your order.' mod='ps_targetpay'}</strong>
    <p>
        {l s='If you want to reorder ' mod='ps_targetpay'}
        <a class="link-button" href="{$reorderUrl}" title="{l s='Reorder'}">{l s='click here' mod='ps_targetpay'}</a>.
    </p>
{/if}
