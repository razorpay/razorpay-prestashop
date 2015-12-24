{if $status == 'ok'}
    <p>{l s='Your order has been completed.' mod='razorpay'}
        <br /><br />{l s='For any questions or for further information, please contact our' mod='razorpay'} <a href="{$base_dir}contact-form.php">{l s='customer support' mod='razorpay'}</a>.
    </p>
{else}
    <p class="warning">
        {l s='We noticed a problem with your order. If you think this is an error, you can contact our' mod='razorpay'}
        <a href="{$base_dir}contact-form.php">{l s='customer support' mod='razorpay'}</a>.
    </p>
{/if}
