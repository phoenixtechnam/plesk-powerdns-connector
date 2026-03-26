.PHONY: install test lint fix analyse package clean ci

install:
	composer install

test:
	./src/plib/vendor/bin/phpunit --testsuite Unit

lint:
	./src/plib/vendor/bin/php-cs-fixer fix --dry-run --diff

fix:
	./src/plib/vendor/bin/php-cs-fixer fix

analyse:
	./src/plib/vendor/bin/phpstan analyse

package: clean
	composer install --no-dev --optimize-autoloader
	cd src && zip -r ../powerdns.zip .
	composer install

clean:
	rm -f powerdns.zip

ci: test lint analyse
