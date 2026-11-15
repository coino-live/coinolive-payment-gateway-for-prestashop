<style>
  .coinolive-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin-bottom:8px}
  .coinolive-card{border:1px solid #dbe6e9;border-radius:6px;padding:12px 14px;background:#fff}
  .coinolive-card span,.coinolive-card em{display:block;color:#6c868e;font-size:12px;font-style:normal}
  .coinolive-card strong{display:block;font-size:18px;word-break:break-all}
  .coinolive-total{border-color:#25b9d7;box-shadow:inset 3px 0 0 #25b9d7}
  .coinolive-total strong{font-size:22px}
  .coinolive-tx+.coinolive-tx{margin-top:6px}
</style>
<div class="panel">
  <h3><i class="icon-bar-chart"></i> Coino Live
    <span class="panel-heading-action">
      {if $cl.dashboard_url}<a class="btn btn-default" href="{$cl.dashboard_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$cl.t.open_dashboard|escape:'html':'UTF-8'}</a>{/if}
      <a class="btn btn-default" href="{$cl.settings|escape:'html':'UTF-8'}"><i class="icon-cogs"></i> {$cl.t.settings|escape:'html':'UTF-8'}</a>
    </span>
  </h3>
  {if !$cl.configured}
    <div class="alert alert-warning">{$cl.t.not_configured|escape:'html':'UTF-8'}</div>
  {else}
    <h4>{$cl.t.balance|escape:'html':'UTF-8'}</h4>
    {if $cl.balance_error}
      <div class="alert alert-danger">{$cl.balance_error|escape:'html':'UTF-8'}</div>
    {else}
      <div class="coinolive-cards">
        <div class="coinolive-card coinolive-total"><span>{$cl.t.total|escape:'html':'UTF-8'}</span><strong>{$cl.total|escape:'html':'UTF-8'}</strong>
          {if $cl.dashboard_url}<a href="{$cl.dashboard_url|escape:'html':'UTF-8'}/wallet/withdraw" target="_blank" rel="noopener">{$cl.t.withdraw|escape:'html':'UTF-8'} &rarr;</a>{/if}
        </div>
        {foreach $cl.balances as $b}
          <div class="coinolive-card"><span>{$b.label|escape:'html':'UTF-8'}</span><strong>{$b.balance|escape:'html':'UTF-8'}</strong><em>{$b.usd|escape:'html':'UTF-8'}</em></div>
        {/foreach}
      </div>
      <p class="help-block">{if $cl.balances}{$cl.t.balance_note|escape:'html':'UTF-8'}{else}{$cl.t.no_balance|escape:'html':'UTF-8'}{/if}</p>
    {/if}

    <h4>{$cl.t.payments|escape:'html':'UTF-8'}</h4>
    <form method="get" action="index.php" class="form-inline" style="margin-bottom:12px">
      <input type="hidden" name="controller" value="AdminCoinoLive">
      <input type="hidden" name="token" value="{$cl.token|escape:'html':'UTF-8'}">
      {if $cl.status}<input type="hidden" name="status" value="{$cl.status|escape:'html':'UTF-8'}">{/if}
      {foreach $cl.tabs as $tab}
        <a href="{$tab.href|escape:'html':'UTF-8'}" class="btn btn-{if $tab.active}primary{else}default{/if} btn-sm">{$tab.name|escape:'html':'UTF-8'}</a>
      {/foreach}
      <span style="margin-left:12px"><input type="text" name="reference" value="{$cl.reference|escape:'html':'UTF-8'}" placeholder="{$cl.t.reference|escape:'html':'UTF-8'}" class="form-control"></span>
      <button class="btn btn-default"><i class="icon-search"></i> {$cl.t.search|escape:'html':'UTF-8'}</button>
    </form>
    {if $cl.payments_error}
      <div class="alert alert-danger">{$cl.payments_error|escape:'html':'UTF-8'}</div>
    {else}
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>{$cl.t.date|escape:'html':'UTF-8'}</th><th>{$cl.t.order|escape:'html':'UTF-8'}</th><th>{$cl.t.status|escape:'html':'UTF-8'}</th><th>{$cl.t.amount|escape:'html':'UTF-8'}</th><th>{$cl.t.received|escape:'html':'UTF-8'}</th><th>{$cl.t.transactions|escape:'html':'UTF-8'}</th><th></th></tr></thead>
          <tbody>
            {foreach $cl.payments as $p}
              <tr>
                <td>{$p.date|escape:'html':'UTF-8'}</td>
                <td>{if $p.order_url}<a href="{$p.order_url|escape:'html':'UTF-8'}">{$p.order|escape:'html':'UTF-8'}</a>{else}{$p.order|escape:'html':'UTF-8'}{/if}</td>
                <td><span class="badge" style="background:{$p.color|escape:'html':'UTF-8'}">{$p.status_name|escape:'html':'UTF-8'}</span></td>
                <td>{$p.amount|escape:'html':'UTF-8'}{if $p.usd}<br><small class="text-muted">{$p.usd|escape:'html':'UTF-8'}</small>{/if}</td>
                <td>{$p.received|escape:'html':'UTF-8'}{if $p.missing}<br><small class="text-danger">{$p.missing|escape:'html':'UTF-8'}</small>{/if}</td>
                <td>
                  {foreach $p.deposits as $d}
                    <div class="coinolive-tx">{$d.amount|escape:'html':'UTF-8'} <small class="text-muted">{$d.network|escape:'html':'UTF-8'}</small><br>
                      {if $d.explorer}<a href="{$d.explorer|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" title="{$d.hash|escape:'html':'UTF-8'}"><code>{$d.short|escape:'html':'UTF-8'}</code></a>{else}<code title="{$d.hash|escape:'html':'UTF-8'}">{$d.short|escape:'html':'UTF-8'}</code>{/if}
                    </div>
                  {foreachelse}&mdash;{/foreach}
                </td>
                <td>{if $p.checkout}<a href="{$p.checkout|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$cl.t.payment_page|escape:'html':'UTF-8'}</a>{/if}</td>
              </tr>
            {foreachelse}
              <tr><td colspan="7" class="text-center">{$cl.t.no_payments|escape:'html':'UTF-8'}</td></tr>
            {/foreach}
          </tbody>
        </table>
      </div>
      <div class="text-right">
        {if $cl.newer}<a class="btn btn-default" href="{$cl.newer|escape:'html':'UTF-8'}">&larr; {$cl.t.newer|escape:'html':'UTF-8'}</a>{/if}
        {if $cl.older}<a class="btn btn-default" href="{$cl.older|escape:'html':'UTF-8'}">{$cl.t.older|escape:'html':'UTF-8'} &rarr;</a>{/if}
      </div>
    {/if}
  {/if}
</div>
