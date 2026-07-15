<?php

use App\Http\Controllers\ChatController;

// The live "what the assistant is doing" label streamed to the chat's
// typing indicator while a tool call runs.

it('labels the NetSuite tools by what they do', function () {
    expect(ChatController::toolActivityLabel('netsuite_suiteql'))
        ->toBe('Querying NetSuite (SuiteQL)')
        ->and(ChatController::toolActivityLabel('netsuite_get_record'))
        ->toBe('Fetching a NetSuite record');
});

it('labels the native web tools', function () {
    expect(ChatController::toolActivityLabel('web_search'))
        ->toBe('Searching the web')
        ->and(ChatController::toolActivityLabel('web_fetch'))
        ->toBe('Reading a web page');
});

it('humanizes Composio APP_ACTION_WORDS tool names', function () {
    expect(ChatController::toolActivityLabel('SLACK_SEND_MESSAGE'))
        ->toBe('Slack · send message')
        ->and(ChatController::toolActivityLabel('HUBSPOT_LIST_CONTACTS'))
        ->toBe('Hubspot · list contacts');
});

it('falls back to Using {name} for anything else', function () {
    expect(ChatController::toolActivityLabel('my_custom_tool'))
        ->toBe('Using my_custom_tool');
});
