<?php
use Ideia\Money;

return [
    'converte formatos comuns para centavos' => function () {
        same(123456, Money::toCents('1.234,56'));
        same(123456, Money::toCents('1 234,56'));
        same(123456, Money::toCents('1234.56'));
        same(123456, Money::toCents('1,234.56'));
        same(1250, Money::toCents('12,5'));
        same(123400, Money::toCents('1,234'));
        same(-5000, Money::toCents('-50'));
        same(-5000, Money::toCents('(50,00)'));
        same(0, Money::toCents(''));
        same(1000, Money::toCents(10));
        same(1001, Money::toCents('10.005'));
    },
    'rejeita texto inválido' => function () {
        throws(fn() => Money::toCents('abc'));
    },
    'formata' => function () {
        same('1 234,56', Money::format(123456));
        same('-0,05', Money::format(-5));
        same('1234.56', Money::decimal(123456));
    },
];
