{if $coinolive_text}
  <div class="alert {if $coinolive_status == 'paid'}alert-success{elseif $coinolive_status == 'expired' || $coinolive_status == 'cancelled'}alert-danger{else}alert-info{/if}">
    <p>{$coinolive_text|escape:'html':'UTF-8'}</p>
    {if $coinolive_link}
      <p><a class="btn btn-primary" href="{$coinolive_link|escape:'html':'UTF-8'}">{$coinolive_button|escape:'html':'UTF-8'}</a></p>
    {/if}
  </div>
{/if}
