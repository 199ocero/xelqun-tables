<?php

namespace Xelqun\Tables\Schema;

final class SchemaVersion
{
    /**
     * Major version of the JSON contract. Bump it when a key is renamed,
     * removed or changes meaning. Adding optional keys needs no bump.
     */
    public const int CURRENT = 1;

    public const string PACKAGE = '0.1.0';
}
