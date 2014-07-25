tester = vendor/bin/tester
tests_dir = tests/
coverage_name = coverage.html
php_ini = $(tests_dir)php-unix.ini

.PHONY: test coverage clean
test:
		@$(tester) -c $(php_ini) $(tests_dir)

coverage:
		@$(tester) -c $(php_ini) --coverage $(coverage_name) --coverage-src LeanMapperQuery/ $(tests_dir)

clean:
		@rm -f $(coverage_name)
