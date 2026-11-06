<?php

namespace Xelqun\Tables\Security;

use Xelqun\Forms\Exceptions\InvalidFormToken;
use Xelqun\Forms\Security\FormTokenEncrypter;
use Xelqun\Tables\Table;

/**
 * Rebuilds a table from its token. Forms only accepts a Form class and this
 * only accepts a Table class, so a token works on one package's endpoints.
 */
final class TableTokenEncrypter
{
    public function __construct(private readonly FormTokenEncrypter $tokens) {}

    public function resolveTable(string $value): Table
    {
        $token = $this->tokens->decrypt($value);

        if (! is_subclass_of($token->formClass, Table::class)) {
            throw InvalidFormToken::make('the token does not describe a table');
        }

        $this->tokens->assertUsable($token, (int) config('xelqun-tables.token_ttl', 720));

        /** @var class-string<Table> $tableClass */
        $tableClass = $token->formClass;
        $context = $token->context['context'] ?? [];
        $table = new $tableClass(is_array($context) ? $context : []);

        if (is_string($token->context['id'] ?? null)) {
            $table->id($token->context['id']);
        }

        if (! $table->authorize(auth()->user())) {
            throw InvalidFormToken::make('this table is unauthorized');
        }

        return $table;
    }
}
