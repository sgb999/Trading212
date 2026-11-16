<?php

namespace App\Mcp\Resources;

use App\Mcp\CurrentAgent;
use App\Mcp\Tools\GetMyPermissionsTool;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('permissions')]
#[Uri('trading://permissions')]
#[MimeType('application/json')]
#[Description('Your role, granted permissions and trading limits as JSON (same data as the get-my-permissions tool).')]
class PermissionsResource extends Resource
{
    public function shouldRegister(CurrentAgent $currentAgent): bool
    {
        return $currentAgent->check();
    }

    public function handle(CurrentAgent $currentAgent): Response
    {
        return Response::text((string) json_encode(
            GetMyPermissionsTool::describe($currentAgent->agent()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
