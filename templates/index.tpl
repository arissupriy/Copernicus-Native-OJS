{**
 * plugins/importexport/copernicusNative/templates/index.tpl
 * Copernicus XML Export (Native)
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
        <h1 class="app__pageHeading">
                {$pageTitle}
        </h1>

        <p>{translate key="plugins.importexport.copernicusNative.description"}</p>

        <form id="exportIssuesXmlForm" class="pkp_form" action="{plugin_url path="exportIssues"}" method="post">
                {csrf}
                {fbvFormArea id="issuesXmlForm"}
                        {capture assign=issuesListGridUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.issues.ExportableIssuesListGridHandler" op="fetchGrid" escape=false}{/capture}
                        {load_url_in_div id="issuesListGridContainer" url=$issuesListGridUrl}
                        {fbvFormButtons submitText="plugins.importexport.copernicusNative.exportIssues" hideCancel="true"}
                {/fbvFormArea}
        </form>
{/block}
