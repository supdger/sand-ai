<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Application\Agent\AgentReadonlyToolCatalog;
use app\Identity\Contract\IdentityContext;

/** Metadata only: resolves current publication and IAM environment; never invokes a tool. */
final class PublishedReadonlyToolGateway
{
    public function read(IdentityContext $context,string $code,int $version):array
    {
        $catalog=new AgentReadonlyToolCatalog();
        $frozen=$catalog->freeze($context->environmentId,[['code'=>$code,'version'=>$version,'business_scope'=>'lawyer_legal_search']]);
        $catalog->assertCurrent($context->environmentId,$frozen);
        $tool=$frozen[0];
        $projection = ['code'=>$code,'version'=>$version,'environment_id'=>$context->environmentId,'state'=>'published','effect_class'=>'read_only',
            'input_schema'=>$tool['input_schema'],'output_schema'=>$tool['output_schema'],'snapshot_sha256'=>$tool['snapshot_sha256']];
        if ($tool['adapter'] === 'mcp_streamable_http') {
            // The catalog above verifies the published registry hash before exposing this capability.
            $definition = (new McpReadonlyToolRegistry())->require($code);
            if (LegalFulltextAdapter::supports($definition['adapter']['kind'] ?? null)) {
                $projection['result_contract'] = 'legal_fulltext_fetch@1';
                $projection['result_visibility'] = 'private_fulltext';
            }
        }
        return $projection;
    }
}
