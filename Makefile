.PHONY: install test lint fix analyse package clean ci

install:
	composer install

test:
	./src/plib/vendor/bin/phpunit

lint:
	./src/plib/vendor/bin/php-cs-fixer fix --dry-run --diff

fix:
	./src/plib/vendor/bin/php-cs-fixer fix

analyse:
	./src/plib/vendor/bin/phpstan analyse

package: clean
	composer install --no-dev --optimize-autoloader
	cd src && zip -r ../powerdns.zip . -x '*/vendor/phpunit/*' '*/vendor/phpstan/*' '*/vendor/friendsofphp/*'
	composer install

clean:
	rm -f powerdns.zip

ci: test lint analyse
